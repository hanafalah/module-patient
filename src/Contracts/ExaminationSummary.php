<?php

namespace Zahzah\ModulePatient\Contracts;

use Zahzah\LaravelSupport\Contracts\DataManagement;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

interface ExaminationSummary extends DataManagement{
    public function examinationSummary(mixed $conditionals = null): builder;
    public function prepareStoreExaminationSummary(? array $attributes = null): Model;
}