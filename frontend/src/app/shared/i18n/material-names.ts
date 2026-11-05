import {TranslateService} from '@ngx-translate/core';

/**
 * A material's name in the language shown: a seeded material's `materials.<code>`, else (one an admin added) its own
 * name. The pages put the names in where they load materials (a scene, the catalogue), so every list, picker, card
 * and drawing reads them as they are.
 */
export function materialName(translate: TranslateService, material: {code: string; name: string}): string {
    const key = `materials.${material.code}`;
    const text = translate.instant(key) as string;
    return text === key ? material.name : text;
}

export function localizeMaterials<T extends {code: string; name: string}>(translate: TranslateService, materials: T[]): T[] {
    return materials.map((material) => ({...material, name: materialName(translate, material)}));
}
