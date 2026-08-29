<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum Mood: string
{
    use HasOptions;

    case Happy = 'happy';
    case Calm = 'calm';
    case Excited = 'excited';
    case Grateful = 'grateful';
    case Reflective = 'reflective';
    case Sad = 'sad';
    case Anxious = 'anxious';
    case Angry = 'angry';
    case Tired = 'tired';
    case Neutral = 'neutral';
    case Custom = 'custom';
}
