import {NgTemplateOutlet} from '@angular/common';
import {ChangeDetectionStrategy, Component, computed, input, output, signal} from '@angular/core';
import {FurnitureDefinition, FurnitureDefinitionBody} from '@app/models/furniture';
import {ModelAsset} from '@app/models/model-asset';
import {ProjectFile} from '@app/models/project';
import {Group, Material, Room, SceneDefinition, SceneModel, SpotHeight} from '@app/models/scene';
import {IconComponent} from '@app/shared/icon/icon.component';
import {ElementKind} from '@app/viewer/engine/selection';

import {CustomParamsComponent} from './custom-params.component';
import {ElementFields, FieldDef, formatFieldValue, isChanged, parseFieldValue} from './element-fields';
import {SpotHeightsComponent} from './spot-heights.component';

/** A titled group of the inspector's fields (untitled: an element with one group). */
interface FieldSection {
    title: string | null;
    folded: boolean;
    fields: FieldDef[];
}

export interface FieldChange {
    key: string;
    value: unknown;
}

/**
 * Form for the selected element, built from `ElementFields`. Each field commits on its own (change event:
 * blur, Enter, picking an option) when the value is valid and differs from the element's.
 */
@Component({
    selector: 'fd-inspector',
    imports: [CustomParamsComponent, SpotHeightsComponent, IconComponent, NgTemplateOutlet],
    templateUrl: './inspector.component.html',
    styleUrl: './inspector.component.scss',
    changeDetection: ChangeDetectionStrategy.OnPush,
})
export class InspectorComponent {
    public readonly kind = input.required<ElementKind>();
    public readonly element = input.required<Record<string, unknown>>();
    public readonly materials = input<Material[]>([]);
    public readonly rooms = input<Room[]>([]);
    public readonly groups = input<Group[]>([]);
    /** Furniture definitions an item may be switched to (the project's choice). */
    public readonly definitions = input<FurnitureDefinition[]>([]);
    /** The definitions the scene's Custom items use (also ones no longer offered). */
    public readonly sceneDefinitions = input<SceneDefinition[]>([]);
    /** 3D models a Model item may be switched to (the project's choice). */
    public readonly models = input<ModelAsset[]>([]);
    /** The models the scene's Model items draw (also ones no longer offered). */
    public readonly sceneModels = input<SceneModel[]>([]);
    /** The project's image files, for the pictures of a Custom item. */
    public readonly images = input<ProjectFile[]>([]);
    public readonly disabled = input(false);

    public readonly fieldChange = output<FieldChange>();
    /** The image picker of a Custom item was opened: the host may refresh `images`. */
    public readonly imagesRefresh = output<void>();

    /**
     * The element's fields in their groups: a field with a `section` starts one, the fields up to the next go in it; a
     * group whose fields all do not apply to the element is left out.
     */
    protected readonly sections = computed((): FieldSection[] => {
        const element = this.element();
        const sections: FieldSection[] = [];
        for (const def of ElementFields[this.kind()]) {
            if (def.section !== undefined || sections.length === 0) {
                sections.push({title: def.section ?? null, folded: def.folded ?? false, fields: []});
            }
            if (def.when?.(element) ?? true) {
                sections[sections.length - 1].fields.push(def.labelOf === undefined ? def : {...def, label: def.labelOf(element)});
            }
        }
        return sections.filter((section) => section.fields.length > 0);
    });
    protected readonly errors = signal<Record<string, string>>({});
    /**
     * The definitions to pick from, with the item's own first when it is not among them (deleted, another library), or a
     * "none" entry when the item has lost its definition.
     */
    protected readonly definitionChoices = computed((): {id: number | ''; name: string}[] => {
        const choices: {id: number | ''; name: string}[] = this.definitions().map((d) => ({id: d.id, name: d.name}));
        const id = this.element()['definitionId'];
        if (choices.some((c) => c.id === id)) {
            return choices;
        }
        const own = this.sceneDefinitions().find((d) => d.id === id);
        return [own !== undefined ? {id: own.id, name: `${own.name} (not offered)`} : {id: '', name: '— none —'}, ...choices];
    });
    /** The models to pick from, with the item's own first when it is not among them (deleted, another library). */
    protected readonly modelChoices = computed((): {id: number | ''; name: string}[] => {
        const choices: {id: number | ''; name: string}[] = this.models().map((m) => ({id: m.id, name: m.name}));
        const id = this.element()['modelId'];
        if (choices.some((c) => c.id === id)) {
            return choices;
        }
        const own = this.sceneModels().find((m) => m.id === id);
        return [own !== undefined ? {id: own.id, name: `${own.name} (not offered)`} : {id: '', name: '— none —'}, ...choices];
    });
    /** The body of the item's furniture definition. */
    protected readonly definitionBody = computed((): FurnitureDefinitionBody | null => {
        const id = this.element()['definitionId'];
        return (this.sceneDefinitions().find((d) => d.id === id) ?? this.definitions().find((d) => d.id === id))?.definition ?? null;
    });

    /** The template context's fields, typed. */
    protected asFields(fields: unknown): FieldDef[] {
        return fields as FieldDef[];
    }

    protected value(def: FieldDef): string {
        return formatFieldValue(def, this.element()[def.key]);
    }

    protected hint(def: FieldDef): string | null {
        return def.hint?.(this.element()) ?? null;
    }

    /** The element's size (mm), which the definition's form evaluates at. */
    protected size(key: 'width' | 'depth' | 'height'): number {
        const value = this.element()[key];
        return typeof value === 'number' ? value : 0;
    }

    /** Current params of the element for the definition's form. */
    protected params(def: FieldDef): Record<string, unknown> {
        const value = this.element()[def.key];
        return typeof value === 'object' && value !== null && !Array.isArray(value) ? (value as Record<string, unknown>) : {};
    }

    /** A plot's spot heights (none in older versions). */
    protected spots(def: FieldDef): SpotHeight[] {
        const value = this.element()[def.key];
        return Array.isArray(value) ? (value as SpotHeight[]) : [];
    }

    protected commitValue(def: FieldDef, value: unknown): void {
        if (isChanged(this.element()[def.key], value)) {
            this.fieldChange.emit({key: def.key, value});
        }
    }

    protected commit(def: FieldDef, event: Event): void {
        const target = event.target as HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement;
        const raw = target instanceof HTMLInputElement && target.type === 'checkbox' ? String(target.checked) : target.value;
        const parsed = parseFieldValue(def, raw);
        if (!parsed.ok) {
            this.errors.update((all) => ({...all, [def.key]: parsed.error}));
            return;
        }
        this.errors.update((all) => {
            const {[def.key]: _removed, ...rest} = all;
            return rest;
        });
        this.commitValue(def, parsed.value);
    }
}
