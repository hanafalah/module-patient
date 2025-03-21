<?php

namespace Hanafalah\ModulePatient\Schemas;

use Hanafalah\ModulePatient\Contracts\VisitPatient as ContractsVisitPatient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Hanafalah\ModulePatient\Enums\VisitPatient\{
    Activity,
    ActivityStatus,
    VisitStatus
};
use Hanafalah\ModulePatient\ModulePatient;
use Hanafalah\ModulePatient\Resources\VisitPatient\{
    ShowVisitPatient,
    ViewVisitPatient
};

class VisitPatient extends ModulePatient implements ContractsVisitPatient
{
    protected array $__guard   = ['id'];
    protected array $__add     = ['patient_id', 'visit_code', 'reservation_id', 'queue_number', 'visited_at', 'status'];
    protected string $__entity = 'VisitPatient';
    public static $visit_patient;

    protected array $__resources = [
        'view' => ViewVisitPatient::class,
        'show' => ShowVisitPatient::class
    ];

    protected array $__cache = [
        'show' => [
            'name'     => 'visit-patient',
            'tags'     => ['visit-patient', 'visit-patient-show'],
            'forever'  => true
        ]
    ];

    public function preparePushLifeCycleActivity(Model $visit_patient, Model $visit_patient_model, mixed $activity_status, int|array $statuses): self
    {
        $visit_patient->refresh();
        $prop_activity  = $visit_patient->prop_activity;

        $visit_patient_model->refresh();
        $visit_prop_activity  = $visit_patient_model->prop_activity;

        $statuses = $this->mustArray($statuses);
        $var_life_cycle = Activity::PATIENT_LIFE_CYCLE->value;
        $life_cycle = $prop_activity[$var_life_cycle] ?? [];

        foreach ($statuses as $key => $status) {
            if (!is_numeric($key)) {
                $message = $status;
                $status = $key;
            } else {
                $message = $visit_prop_activity[$activity_status][$status]['message'] ?? null;
            }
            $activity_subject = &$visit_prop_activity[$activity_status];
            $activity_subject[$status] ??= [];
            $visit_model_prop = (array) $activity_subject[$status];
            $activity_by_status = $prop_activity[$activity_status][$status] ?? $visit_model_prop;
            if (isset($message)) {
                $activity_by_status['message'] = $message;
            }
            $existing_activity = collect($life_cycle)->first(function ($activity) use ($status, $message) {
                return isset($activity[$status]) && (isset($message) ? $activity[$status]['message'] == $message : true);
            });
            if (isset($existing_activity)) continue;
            $life_cycle[] = [$status => $activity_by_status];
        }
        $prop_activity[$var_life_cycle] = $life_cycle;
        $visit_patient->setAttribute('prop_activity', $prop_activity);
        $visit_patient->save();
        return $this;
    }

    public function prepareStoreVisitPatient(?array $attributes = null): Model
    {
        $attributes ??= request()->all();

        $attributes['flag'] ??= $this->VisitPatientModel()::CLINICAL_VISIT;
        $visit_patient_model = ($attributes['flag'] == $this->VisitPatientModel()::CLINICAL_VISIT) ? $this->VisitPatientModel() : $this->PharmacySaleModel();
        if (isset($attributes['id'])) {
            $visit_patient_model = $visit_patient_model->withoutGlobalScope($this->VisitPatientModel()::CLINICAL_VISIT)
                ->where(function ($query) use ($attributes) {
                    $query->where('id', $attributes['id'])
                        ->orWhere('visit_code', $attributes['id']);
                })->firstOrFail();
            $attributes['id'] = $visit_patient_model->getKey();
            if ($visit_patient_model->flag != $attributes['flag']) {
                $visit_patient_model = ($attributes['flag'] == $this->VisitPatientModel()::CLINICAL_VISIT) ? $this->VisitPatientModel() : $this->PharmacySaleModel();
                $visit_patient_model = $visit_patient_model->findOrFail($attributes['id']);
            }

            $attributes['patient_id'] = $visit_patient_model->patient_id;
            $attributes['reference_id']   = $visit_patient_model->reference_id ?? null;
            $attributes['reference_type'] = $visit_patient_model->reference_type ?? null;
            $this->createAgent($visit_patient_model, $attributes);
            //ADD TREATMENT
            if (isset($attributes['medic_services'])) {
                $visit_registration = $visit_patient_model->visitRegistration()->whereNull('parent_id')->first();
                $attributes['visit_registration_parent_id']        = $visit_registration->getKey();
                $attributes['visit_registration_medic_service_id'] = $visit_registration->medic_service_id;
                $attributes['visit_patient_id']                    = $visit_patient_model->getKey();
                $attributes['visit_patient_type']                  = $visit_patient_model->getMorphClass();
                $attributes['patient_type_id']                     = $visit_patient_model->prop_patienttype['id'] ?? null;

                $this->schemaContract('visit_registration')->storeServices($attributes);
            }
            $visit_patient_model->save();
        } else {
            $visit_patient_model = $this->newVisitPatient($visit_patient_model, $attributes);
        }

        $transaction = $visit_patient_model->transaction()->firstOrCreate();
        if (isset($visit_patient_model->patient_id)) {
            $patient = $visit_patient_model->patient;

            $transaction->sync($visit_patient_model, ['prop_patient']);
            $transaction->consument_name = $patient->prop_people['name'];
            $transaction->save();
        }

        $this->forgetTags('visit-patient');
        return $visit_patient_model;
    }

    protected function newVisitPatient(Model $visit_patient_model, array &$attributes): Model
    {
        $patient = $this->PatientModel()->find($attributes['patient_id']);
        if (!isset($patient)) throw new \Exception('Patient not found.', 422);

        $visit_patient_model = $visit_patient_model->create([
            'patient_id'     => $patient->getKey(),
            'parent_id'      => $attributes['parent_id'] ?? null,
            'reference_id'   => $attributes['reference_id'] ?? null,
            'reference_type' => $attributes['reference_type'] ?? null,
            'flag'           => $attributes['flag'] ?? null,
            'visited_at'     => now(),
            'status'         => VisitStatus::ACTIVE->value
        ]);

        if ($visit_patient_model->getMorphClass() == $this->VisitPatientModelMorph()) {
            $visit_patient_model->pushActivity(Activity::ADM_VISIT->value, [ActivityStatus::ADM_START->value]);
            $this->preparePushLifeCycleActivity($visit_patient_model, $visit_patient_model, 'ADM_VISIT', ['ADM_START']);

            if (isset($attributes['external_referral'])) {
                $externalRefferal = $this->schemaContract('external_referral');
                $externalRefferal = $externalRefferal->prepareStoreExternalReferral(
                    array_merge($attributes['external_referral'], ["visit_patient_id" => $visit_patient_model->getKey()])
                );
            }
        }

        $visit_patient_model->properties = $attributes['properties'] ?? [];
        $visit_patient_model->sync($patient, [
            'nik',
            'passport',
            'crew_id',
            'bpjs_code',
            'prop_people',
            'medical_record'
        ]);
        $visit_patient_model->setAttribute('prop_patient', $patient->getPropsKey());

        $reference = $patient->reference;
        if (\method_exists($reference, 'hasPhone')) {
            $phone = $reference->hasPhone?->phone ?? null;
        }

        $this->updatePaymentSummary($visit_patient_model, $attributes, $patient)
            ->createAgent($visit_patient_model, $attributes)
            ->createPatientType($visit_patient_model, $attributes)
            ->createConsumentTransaction($visit_patient_model, [
                'name'           => $patient->prop_people['name'],
                'phone'          => $phone ?? null,
                'reference_id'   => $patient->getKey(),
                'reference_type' => $patient->getMorphClass(),
                'patient'        => $patient
            ]);
        $visit_patient_model->save();
        return $visit_patient_model;
    }

    protected function createConsumentTransaction(Model $visit_model, array $attributes): self
    {
        $transaction = $visit_model->transaction;
        if (isset($transaction)) {
            $add = [
                'name'   => $attributes['name'],
                'phone'  => $attributes['phone']
            ];
            if (isset($attributes['reference_id']) && isset($attributes['reference_type'])) {
                $guard = [
                    'reference_id'   => $attributes['reference_id'],
                    'reference_type' => $attributes['reference_type']
                ];
            } else {
                $guard = $add;
            }
            $consument = $this->ConsumentModel()->updateOrCreate($guard, $add);
            if (isset($attributes['patient'])) {
                $consument->setAttribute('prop_patient', $attributes['patient']->getPropsKey());
                $consument->save();
                $consument->refresh();
            }
            $props = [
                'id'    => $consument->getKey(),
                'name'  => $consument->name,
                'phone' => $consument->phone
            ];
            if (count($consument->getPropsKey() ?? []) > 0) {
                $props = $this->mergeArray($props, $consument->getPropsKey());
            }

            $transaction->consument_name = $consument->name;
            $transaction->setAttribute('prop_consument', $props);
            $transaction->save();

            $transaction->transactionHasConsument()->firstOrCreate([
                'consument_id' => $consument->getKey()
            ]);
        }
        return $this;
    }

    protected function updatePaymentSummary(Model &$model, array $attributes, ?Model $patient, ?string $message = null): self
    {
        $attributes['payer_id'] ??= request()->payer_id;
        if (!isset($attributes['payer_id']) && isset($patient)) {
            $patient->modelHasOrganization()->where('organization_type', $this->PayerModelMorph())->delete();
        }

        if (isset($attributes['payer_id'])) {
            $this->createModelHasOrganization($model, $attributes);
        } else {
            if (isset($patient)) {
                $invoice_model = $patient;
                $invoice       = $this->createInvoice($invoice_model);

                $paymentSummary            = $invoice->paymentSummary()->firstOrCreate();
                $paymentSummary->name      = $message ?? "Total Tagihan untuk {$patient->prop_people['name']}";
                $paymentSummary->save();

                $transaction                         = $model->transaction()->firstOrCreate();
                $trx_payment_summary                 = $transaction->paymentSummary;
                $trx_payment_summary->name           = "Total Tagihan untuk {$patient->prop_people['name']}";
                $trx_payment_summary->parent_id      = $paymentSummary->getKey();
                $trx_payment_summary->transaction_id = $transaction->getKey();
                $trx_payment_summary->save();

                $transaction->consument_name = $patient->prop_people['name'];
                $transaction->save();
            }
        }
        return $this;
    }

    protected function createModelHasOrganization(Model &$model, array $attributes)
    {
        $model->modelHasOrganization()->updateOrCreate([
            'reference_id'       => $model->getKey(),
            'reference_type'     => $model->getMorphClass()
        ], [
            'organization_id'    => $attributes['payer_id'],
            'organization_type'  => $this->PayerModelMorph()
        ]);
        $payer   = $this->PayerModel()->findOrFail($attributes['payer_id']);
        $model->sync($payer, ['id', 'name']);
    }

    public function storeVisitPatient(): array
    {
        return $this->transaction(function () {
            return $this->showVisitPatient($this->prepareStoreVisitPatient());
        });
    }

    protected function createPatientType(Model &$model, array $attributes): self
    {
        if (isset($attributes['patient_type_id'])) {
            $patientType = $this->PatientTypeModel()->findOrFail($attributes['patient_type_id']);
            $model->patientTypeHistory()->firstOrCreate(['patient_type_id' => $attributes['patient_type_id']]);
            $model->sync($patientType, ['id', 'name']);
        }
        return $this;
    }

    protected function createAgent(Model &$model, array $attributes): self
    {
        if (isset($attributes['agent_id'])) {
            $model->modelHasOrganization()->updateOrCreate([
                'reference_id'       => $model->getKey(),
                'reference_type'     => $model->getMorphClass(),
                'organization_type'  => $this->AgentModel()->getMorphClass()
            ], [
                'organization_id'    => $attributes['agent_id'],
            ]);
            $agent = $this->AgentModel()->findOrFail($attributes['agent_id']);
            $model->sync($agent, ['id', 'name']);
        }
        return $this;
    }

    public function showUsingRelation(): array
    {
        return [
            'patient',
            'reservation',
            'visitRegistrations' => function ($query) {
                $query->with(['medicService.service', 'patientType', 'headDoctor', 'visitExamination', "visitPatient"]);
            },
            'organizations',
            'transaction.consument',
            'services',
            'payer',
            'agent'
        ];
    }

    public function prepareShowVisitPatient(?Model $model = null, ?array $attributes = null): Model
    {
        $attributes ??= request()->all();

        $model ??= $this->getVisitPatient();
        if (!isset($model)) {
            $id = $attributes['id'] ?? null;
            if (!isset($id)) throw new \Exception('Visit Patient not found.', 422);
            $model = $this->visitPatient()->with($this->showUsingRelation())
                ->where(function ($query) use ($attributes) {
                    $query->where('id', $attributes['id'])
                        ->orWhere('visit_code', $attributes['id']);
                })->firstOrFail();
        } else {
            $model->load($this->showUsingRelation());
        }
        return static::$visit_patient = $model;
    }

    public function showVisitPatient(?Model $model = null)
    {
        return $this->transforming($this->__resources['show'], function () use ($model) {
            return $this->prepareShowVisitPatient($model);
        });
    }

    public function prepareViewPatientPaginate(int $perPage = 50, array $columns = ['*'], string $pageName = 'page', ?int $page = null, ?int $total = null): LengthAwarePaginator
    {
        $attributes ??= request()->all();

        $paginate_options = compact('perPage', 'columns', 'pageName', 'page', 'total');
        $visit_patient = $this->commonPaginate($paginate_options);
        return static::$visit_patient = $visit_patient;
    }

    public function viewVisitPatientPaginate(int $perPage = 50, array $columns = ['*'], string $pageName = 'page', ?int $page = null, ?int $total = null): array
    {
        $paginate_options = compact('perPage', 'columns', 'pageName', 'page', 'total');
        return $this->transforming($this->__resources['view'], function () use ($paginate_options) {
            return $this->prepareViewPatientPaginate(...$this->arrayValues($paginate_options));
        });
    }

    protected function createInvoice($model)
    {
        return $model->invoice()->firstOrCreate([
            'consument_id'   => $model->getKey(),
            'consument_type' => $model->getMorphClass(),
            'billing_at'     => null
        ]);
    }

    public function commonPaginate($paginate_options): LengthAwarePaginator
    {
        return $this->visitPatient()->with(['visitRegistrations' => function ($q) {
            $q->with([
                'medicService.service',
                'patientType',
                'headDoctor'
            ]);
        }, 'organization', 'patient', 'transaction', 'services', 'agent', 'payer'])
            ->orderBy('created_at', 'desc')
            ->paginate(...$this->arrayValues($paginate_options))
            ->appends(request()->all());
    }

    public function prepareDeleteVisitPatient(?array $attributes = null): mixed
    {
        $attributes ??= request()->all();
        if (!isset($attributes['id'])) throw new \Exception('Visit Patient not found.', 422);

        $visit_patient_model = $this->visitPatient()->with([
            'activity' => function ($query) {
                $query->where('activity_flag', Activity::ADM_VISIT->value);
            }
        ])->findOrFail($attributes['id']);
        if (!isset($visit_patient_model->activity)) throw new \Exception('Activity for this visit patient not found.', 422);

        if ($visit_patient_model->activity->activity_status == ActivityStatus::ADM_START->value) {
            $visit_patient_model->status                     = VisitStatus::CANCELLED->value;
            $visit_patient_model->pushActivity(Activity::ADM_VISIT->value, [ActivityStatus::ADM_CANCELLED->value]);
            $this->preparePushLifeCycleActivity($visit_patient_model, $visit_patient_model, 'ADM_VISIT', ['ADM_CANCELLED']);
            $visit_patient_model->save();
            $visit_patient_model->canceling();
            return $visit_patient_model;
        }
        throw new \Exception('Data cannot be cancelled anymore.', 422);
    }

    public function deleteVisitPatient(): bool
    {
        return $this->transaction(function () {
            return $this->prepareDeleteVisitPatient();
        });
    }

    public function visitPatient(mixed $conditionals = null): Builder
    {
        return $this->VisitPatientModel()->conditionals($conditionals)
            ->when(isset(request()->patient_id), function ($query) {
                $query->where('patient_id', request()->patient_id);
            })->withParameters('or');
    }

    public function getVisitPatient(): mixed
    {
        return static::$visit_patient;
    }

    public function addOrChange(?array $attributes = []): self
    {
        $this->updateOrCreate($attributes);
        return $this;
    }
}
