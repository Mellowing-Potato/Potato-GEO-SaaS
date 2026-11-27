<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class KnowledgeDocumentUnavailable extends ConflictHttpException
{
}
