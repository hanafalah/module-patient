<?php

namespace Hanafalah\ModulePatient\Models\EMR;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\SoftDeletes;
use Hanafalah\LaravelHasProps\Concerns\HasProps;
use Hanafalah\LaravelSupport\{
    Concerns\Support\HasActivity,
    Models\BaseModel
};

use Hanafalah\ModulePatient\Enums\{
    VisitRegistration\Activity as VisitRegistrationActivity,
    VisitRegistration\ActivityStatus as VisitRegistrationActivityStatus
};

use Hanafalah\ModulePatient\{
    Enums\VisitPatient\VisitStatus,
    Resources\VisitPatient\ShowVisitPatient,
    Resources\VisitPatient\ViewVisitPatient
};
use Hanafalah\ModuleTransaction\Concerns\HasTransaction;
use Hanafalah\ModulePatient\Enums\VisitPatient\{
    Activity,
    ActivityStatus
};
use Hanafalah\ModulePatient\Enums\VisitRegistration\RegistrationStatus;
use Hanafalah\ModuleTransaction\Concerns\HasPaymentSummary;

class VisitPatient extends BaseModel
{
    use HasUlids, HasTransaction, SoftDeletes;
    use HasProps, HasActivity, HasPaymentSummary;

    const CLINICAL_VISIT = 'CLINICAL_VISIT';
    public static $flag  = 'CLINICAL_VISIT';

    public $incrementing  = false;
    protected $keyType    = 'string';
    protected $primaryKey = 'id';
    protected $list       = [
        'id',
        'parent_id',
        'visit_code',
        'patient_id',
        'reference_id',
        'reference_type',
        'flag',
        'reservation_id',
        'queue_number',
        'visited_at',
        'reported_at',
        'status',
        'props'
    ];
    protected $show = [];

    protected $casts = [
        'name'           => 'string',
        'queue_number'   => 'string',
        'created_at'     => 'datetime',
        'nik'            => 'string',
        'dob'            => 'immutable_date',
        'medical_record' => 'string',
        'visited_at'     => 'datetime',
        'reported_at'    => 'datetime'
    ];

    public function getPropsQuery(): array
    {
        return [
            'name'           => 'props->prop_patient->prop_people->name',
            'dob'            => 'props->prop_patient->prop_people->dob',
            'nik'            => 'props->prop_patient->nik',
            'medical_record' => 'props->prop_patient->medical_record'
        ];
    }

    protected static function booted(): void
    {
        parent::booted();
        static::addGlobalScope(self::CLINICAL_VISIT, function ($query) {
            $query->where('flag', static::$flag);
        });
        static::creating(function ($query) {
            if (!isset($query->visit_code)) {
                $query->visit_code = static::hasEncoding('VISIT_PATIENT');
            }
            if (!isset($query->flag))   $query->flag   = self::CLINICAL_VISIT;
            if (!isset($query->status)) $query->status = VisitStatus::ACTIVE->value;
            if (!isset($query->reservation_id) && $query->visited_at === null) {
                $query->visited_at = now();
            }
        });
        static::updated(function ($query) {
            //WHEN DELETING
            if ($query->isDirty('status') && $query->status == VisitStatus::CANCELLED->value) {
                $payment_summary = $query->paymentSummary;
                if (isset($payment_summary)) {
                    if ($payment_summary->total_amount == $payment_summary->total_debt) {
                        $payment_summary->delete();
                    }
                }

                $query->load([
                    'visitRegistrations' => function ($query) {
                        $query->whereNot('status', RegistrationStatus::CANCELLED->value);
                    }
                ]);
                $visit_registrations = $query->visitRegistrations;
                foreach ($visit_registrations as $visit_registration) {
                    $visit_registration->status = RegistrationStatus::CANCELLED->value;

                    $visit_registration->pushActivity(VisitRegistrationActivity::POLI_SESSION->value, [VisitRegistrationActivityStatus::POLI_SESSION_CANCEL->value]);

                    $visit_registration->save();
                }
            }
        });
    }

    public function toShowApi()
    {
        return new ShowVisitPatient($this);
    }

    public function toViewApi()
    {
        return new ViewVisitPatient($this);
    }

    public function patient()
    {
        return $this->belongsToModel('Patient');
    }
    public function visitRegistration()
    {
        return $this->morphOneModel('VisitRegistration', 'visit_patient');
    }
    public function visitRegistrations()
    {
        return $this->morphManyModel('VisitRegistration', 'visit_patient');
    }
    public function reservation()
    {
        return $this->belongsToModel('Reservation');
    }
    public function patientDischarge()
    {
        return $this->hasOneModel('PatientDischarge', 'visit_patient_id');
    }
    public function modelHasOrganization()
    {
        return $this->morphOneModel('ModelHasOrganization', 'reference');
    }
    public function modelHasOrganizations()
    {
        return $this->morphManyModel('ModelHasOrganization', 'reference');
    }
    public function payer()
    {
        $payer_table          = $this->PayerModel()->getTableName();
        $model_has_table_name = $this->ModelHasOrganizationModel()->getTableName();
        return $this->hasOneThroughModel(
            'Payer',
            'ModelHasOrganization',
            'reference_id',
            'id',
            'id',
            'organization_id'
        )->where($model_has_table_name . '.reference_type', $this->getMorphClass())
            ->where($model_has_table_name . '.organization_type', $this->PayerModelMorph())
            ->select([$payer_table . '.*', $model_has_table_name . '.*', $payer_table . '.id as id']);
    }
    public function agent()
    {
        $agent_table = $this->AgentModel()->getTableName();
        $model_has_table_name = $this->ModelHasOrganizationModel()->getTableName();
        return $this->hasOneThroughModel(
            'Agent',
            'ModelHasOrganization',
            'reference_id',
            'id',
            'id',
            'organization_id'
        )->where('reference_type', $this->getMorphClass())
            ->where('organization_type', $this->AgentModel()->getMorphClass())
            ->select([$agent_table . '.*', $model_has_table_name . '.*', $agent_table . '.id as id']);
    }
    public function organization()
    {
        $organization_table = $this->OrganizationModel()->getTableName();
        $model_has_table_name = $this->ModelHasOrganizationModel()->getTableName();
        return $this->hasOneThroughModel(
            'Organization',
            'ModelHasOrganization',
            'reference_id',
            'id',
            'id',
            'organization_id'
        )->where('reference_type', $this->getMorphClass())
            ->select([$organization_table . '.*', $model_has_table_name . '.*', $organization_table . '.id as id']);
    }
    public function organizations()
    {
        $organization_table = $this->OrganizationModel()->getTableName();
        $model_has_table_name = $this->ModelHasOrganizationModel()->getTableName();
        return $this->hasManyThroughModel(
            'Organization',
            'ModelHasOrganization',
            'reference_id',
            'id',
            'id',
            'organization_id'
        )->where('reference_type', $this->getMorphClass())
            ->select([$organization_table . '.*', $model_has_table_name . '.*', $organization_table . '.id as id']);
    }
    public function modelHasService()
    {
        return $this->morphOneModel('ModelHasService', 'reference');
    }
    public function modelHasServices()
    {
        return $this->morphManyModel('ModelHasService', 'reference');
    }
    public function patientSummary()
    {
        return $this->hasOneModel('PatientSummary', 'visit_patient_id');
    }
    public function patientTypeHistory()
    {
        return $this->hasOneModel('PatientTypeHistory', 'visit_patient_id');
    }
    public function patientTypeHistories()
    {
        return $this->hasManyModel('PatientTypeHistory', 'visit_patient_id');
    }
    public function cardStocks()
    {
        $transaction_model = $this->TransactionModel();
        return $this->hasManyThroughModel(
            'CardStock',
            'Transaction',
            $transaction_model->getTableName() . '.reference_id',
            $transaction_model->getForeignKey(),
            $this->getKeyName(),
            $transaction_model->getKeyName()
        )->where($transaction_model->getTableName() . '.reference_type', $this->getMorphClass());
    }
    public function services()
    {
        return $this->belongsToManyModel(
            'Service',
            'ModelHasService',
            'model_has_services.reference_id',
            'service_id'
        )->where('model_has_services.reference_type', $this->getMorphClass());
    }

    public static array $activityList = [
        Activity::ADM_VISIT->value . '_' . ActivityStatus::ADM_START->value     => ['flag' => 'ADM_START', 'message' => 'Administrasi dibuat'],
        Activity::ADM_VISIT->value . '_' . ActivityStatus::ADM_PROCESSED->value => ['flag' => 'ADM_PROCESSED', 'message' => 'Pasien dalam antrian layanan'],
        Activity::ADM_VISIT->value . '_' . ActivityStatus::ADM_FINISHED->value  => ['flag' => 'ADM_FINISHED', 'message' => 'Pasien selesai layanan'],
        Activity::ADM_VISIT->value . '_' . ActivityStatus::ADM_CANCELLED->value => ['flag' => 'ADM_CANCELLED', 'message' => 'Transaksi dibatalkan'],
    ];
}
