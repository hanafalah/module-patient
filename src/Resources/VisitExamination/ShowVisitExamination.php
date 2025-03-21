<?php

namespace Hanafalah\ModulePatient\Resources\VisitExamination;

class ShowVisitExamination extends ViewVisitExamination
{
    public function toArray(\Illuminate\Http\Request $request): array
    {
        $arr = [
            'visit_registration' => $this->relationValidation('visitRegistration', function () {
                return $this->visitRegistration->toShowApi();
            }),
            'screenings' => [],
            'forms'      => [],
            "addendum"   =>  $this->examinationSummary->addendum ?? null,
            'examination_summary' => $this->relationValidation('examinationSummary', function () {
                return $this->examinationSummary->toShowApi();
            })
        ];
        if (isset($this->screenings)) {
            $arr['screenings'] = $this->screenings;
        }
        if (isset($this->forms)) {
            $arr['forms']      = $this->forms;
        }
        $arr = array_merge(parent::toArray($request), $arr);

        return $arr;
    }
}
