<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Entity\Enum;

/** What an upload ticket creates: a file of a project or a 3D model. */
enum UploadKindEnum: string
{
	case ProjectFile = 'ProjectFile';
	case Model = 'Model';
}
