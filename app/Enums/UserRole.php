<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Instructor = 'instructor';
    case Student = 'student';
    // Passport staff account that isn't an instructor (support staff).
    case Staff = 'staff';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'แอดมิน',
            self::Instructor => 'อาจารย์',
            self::Student => 'นักศึกษา',
            self::Staff => 'บุคลากร',
        };
    }
}
