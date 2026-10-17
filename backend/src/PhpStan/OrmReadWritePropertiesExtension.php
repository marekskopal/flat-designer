<?php

declare(strict_types=1);

namespace FlatDesigner\PhpStan;

use MarekSkopal\ORM\Attribute\Column;
use MarekSkopal\ORM\Attribute\ColumnEnum;
use MarekSkopal\ORM\Attribute\ManyToOne;
use PHPStan\Reflection\Php\PhpPropertyReflection;
use PHPStan\Reflection\PropertyReflection;
use PHPStan\Rules\Properties\ReadWritePropertiesExtension;

final class OrmReadWritePropertiesExtension implements ReadWritePropertiesExtension
{
	private const array OrmAttributes = [Column::class, ManyToOne::class, ColumnEnum::class];

	public function isAlwaysRead(PropertyReflection $property, string $propertyName): bool
	{
		return $this->hasOrmAttribute($property);
	}

	public function isAlwaysWritten(PropertyReflection $property, string $propertyName): bool
	{
		return $this->hasOrmAttribute($property);
	}

	public function isInitialized(PropertyReflection $property, string $propertyName): bool
	{
		return $this->hasOrmAttribute($property);
	}

	private function hasOrmAttribute(PropertyReflection $property): bool
	{
		//@phpstan-ignore-next-line
		if (!($property instanceof PhpPropertyReflection)) {
			return false;
		}

		foreach ($property->getNativeReflection()->getAttributes() as $attribute) {
			if (in_array($attribute->getName(), self::OrmAttributes, true)) {
				return true;
			}
		}

		return false;
	}
}
