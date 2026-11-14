<?php

namespace App\Services\Exceptions;

/** A deployment/configuration error, never a reason to silently open other skills. */
final class SkillRegistryException extends \RuntimeException {}
