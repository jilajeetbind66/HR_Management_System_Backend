<?php

namespace App\Support;

final class EmployeeConstants
{
    public const GENDER_MALE = 'Male';
    public const GENDER_FEMALE = 'Female';
    public const GENDER_OTHER = 'Other';

    public const GENDERS = [
        self::GENDER_MALE,
        self::GENDER_FEMALE,
        self::GENDER_OTHER,
    ];
}
