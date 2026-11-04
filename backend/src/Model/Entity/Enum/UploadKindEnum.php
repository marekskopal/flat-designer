<?php

declare(strict_types=1);

namespace Izbik\Model\Entity\Enum;

/** What an upload ticket creates: a file of a project, a 3D model, or a new project from a project file. */
enum UploadKindEnum: string
{
	case ProjectFile = 'ProjectFile';
	case Model = 'Model';
	case ProjectImport = 'ProjectImport';
}
