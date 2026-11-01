import {ChangeDetectionStrategy, Component, computed, ElementRef, inject, input, output, signal, viewChild} from '@angular/core';
import {CatalogueLabels, FurnitureCatalogue, FurnitureDefinition} from '@app/models/furniture';
import {OpeningType, Roof, Room, Scene, Wall} from '@app/models/scene';
import {IconComponent, IconName} from '@app/shared/icon/icon.component';
import {polygonCentroid, wallLength} from '@app/viewer/engine/plan-geometry';

import {EditorStore, newRoomFields} from './editor.store';
import {OpeningTypeLabels} from './element-fields';

/** Size of a new wall and a new room, mm. */
const NewWallLength = 3000;
const NewRoomSize = 3000;
const NewVoidSize = 1200;
const NewRailingLength = 2000;
/** Width of an opening added here (the server's default, used to centre it on the wall). */
const NewOpeningWidths: Record<OpeningType, number> = {
    Door: 900,
    DoubleDoor: 1400,
    SlidingDoor: 900,
    PocketDoor: 900,
    Passage: 900,
    Window: 1200,
    Niche: 600,
    GarageDoor: 2500,
};

const OpeningIcons: Record<OpeningType, IconName> = {
    Door: 'door',
    DoubleDoor: 'door',
    SlidingDoor: 'door',
    PocketDoor: 'door',
    Passage: 'rooms',
    Window: 'window',
    Niche: 'image',
    GarageDoor: 'door',
};

const KindIcons: Readonly<Record<string, IconName>> = {
    Sink: 'bath',
    Washbasin: 'bath',
    Bathtub: 'bath',
    Toilet: 'bath',
    FlushPlate: 'bath',
    TowelRadiator: 'bath',
    WashingMachine: 'box',
    Hob: 'cabinet',
    LedStrip: 'bulb',
    Fixture: 'bulb',
    Mirror: 'image',
    Fence: 'railing',
    Column: 'column',
    Beam: 'column',
    Chimney: 'column',
};

const CatalogueIcons: Record<FurnitureCatalogue, IconName> = {
    kitchen: 'cabinet',
    storage: 'cabinet',
    seating: 'chair',
    tables: 'table',
    bedroom: 'bed',
    bathroom: 'bath',
    lighting: 'bulb',
    electrical: 'plug',
    decor: 'image',
    building: 'column',
    outdoor: 'tree',
    roof: 'roof',
};

/** Where a definition is filed: its `catalogue`, else by its electrical category (lights, the rest), else none (Other). */
function catalogueOf(definition: FurnitureDefinition): FurnitureCatalogue | null {
    const body = definition.definition;
    if (body.catalogue !== undefined) {
        return body.catalogue;
    }
    if (body.category === undefined) {
        return null;
    }
    return body.category === 'light' ? 'lighting' : 'electrical';
}

/** One thing the library adds: `choice` says what (`item:<kind>`, `custom:<id>`, `model:<id>`, `wall`, `opening:<type>`, …). */
interface AddEntry {
    choice: string;
    label: string;
    icon: IconName;
    description: string;
    /** Why it cannot be added now (a door needs a selected wall); null when it can. */
    blocked: string | null;
}

interface AddGroup {
    label: string;
    entries: AddEntry[];
    /** A note over the group (what its entries need). */
    note: string | null;
}

interface AddCategory {
    key: string;
    label: string;
    icon: IconName;
    groups: AddGroup[];
}

/**
 * The editor's Add library: building parts, furniture definitions and 3D products by category, with a search over all
 * of them. A new element goes to the selected room's centre, or where the camera looks.
 */
@Component({
    selector: 'fd-add-library',
    imports: [IconComponent],
    template: `
        <div class="search">
            <fd-icon name="search" />
            <input
                #search
                type="search"
                placeholder="Search building parts, furniture, products…"
                aria-label="Search"
                [value]="query()"
                (input)="query.set($any($event.target).value)"
                (keydown.enter)="addFirst()"
            />
            <kbd>Esc</kbd>
        </div>
        @if (query().trim() === '') {
            <nav class="cats" aria-label="Categories">
                @for (category of categories(); track category.key) {
                    <button type="button" class="cat" [class.cat--active]="category === active()" (click)="activeKey.set(category.key)">
                        <fd-icon [name]="category.icon" [size]="16" />
                        <span class="cat__label">{{ category.label }}</span>
                        <span class="cat__count">{{ count(category) }}</span>
                    </button>
                }
            </nav>
        }
        <div class="grid">
            @for (group of shown(); track group.label) {
                <h3 class="group">{{ group.label }}</h3>
                @if (group.note; as note) {
                    <p class="note"><fd-icon name="info" [size]="15" />{{ note }}</p>
                }
                <div class="tiles">
                    @for (entry of group.entries; track entry.choice) {
                        <button
                            type="button"
                            class="tile"
                            [disabled]="entry.blocked !== null || editor.busy()"
                            [title]="entry.blocked ?? entry.description"
                            (click)="add(entry.choice)"
                        >
                            <fd-icon [name]="entry.icon" [size]="28" />
                            <span class="tile__label">{{ entry.label }}</span>
                        </button>
                    }
                </div>
            } @empty {
                <p class="empty">Nothing matches “{{ query() }}”.</p>
            }
        </div>
    `,
    styleUrl: './add-library.component.scss',
    changeDetection: ChangeDetectionStrategy.OnPush,
    host: {'(keydown.escape)': 'closed.emit()'},
})
export class AddLibraryComponent {
    public readonly scene = input.required<Scene>();
    /** Plan point the camera looks at, where new elements go. */
    public readonly viewCenter = input.required<() => {x: number; y: number}>();
    /** Something was added, or Esc pressed. */
    public readonly closed = output();

    protected readonly editor = inject(EditorStore);
    protected readonly query = signal('');
    protected readonly activeKey = signal('building');
    private readonly search = viewChild.required<ElementRef<HTMLInputElement>>('search');

    private readonly selectedWall = computed((): Wall | null => {
        const selection = this.editor.selection();
        return selection?.kind === 'wall' ? (this.scene().walls.find((w) => w.id === selection.id) ?? null) : null;
    });
    private readonly selectedPitchedRoof = computed((): Roof | null => {
        const selection = this.editor.selection();
        const roof = selection?.kind === 'roof' ? (this.scene().roofs ?? []).find((r) => r.id === selection.id) : undefined;
        return roof === undefined || roof.shape === 'Flat' ? null : roof;
    });
    private readonly selectedRoom = computed((): Room | null => {
        const selection = this.editor.selection();
        return selection?.kind === 'room' ? (this.scene().rooms.find((r) => r.id === selection.id) ?? null) : null;
    });

    protected readonly categories = computed((): AddCategory[] => {
        const kinds = this.editor.itemKinds();
        const kindEntry = (kind: {kind: string; description: string}): AddEntry => ({
            choice: `item:${kind.kind}`,
            label: kind.kind.replace(/([a-z])([A-Z])/g, '$1 $2'),
            icon: KindIcons[kind.kind] ?? 'box',
            description: kind.description,
            blocked: null,
        });
        const plain = (choice: string, label: string, icon: IconName, description = ''): AddEntry => ({
            choice,
            label,
            icon,
            description,
            blocked: null,
        });
        const wallMissing = this.selectedWall() === null ? 'Select a wall first' : null;
        const categories: AddCategory[] = [
            {
                key: 'building',
                label: 'Building',
                icon: 'roof',
                groups: [
                    {
                        label: 'Structure',
                        note: null,
                        entries: [
                            plain('wall', 'Wall', 'wall', 'A 3 m wall where the camera looks; Draw walls (W) draws runs'),
                            plain('room', 'Room', 'rooms', 'A 3 × 3 m room; Room from walls (M) outlines one'),
                            plain('stair', 'Stairs', 'stairs', 'Climbs to the floor above'),
                            plain('void', 'Floor void', 'void', 'A hole through the floor'),
                            plain('railing', 'Railing', 'railing', 'Draw railing (B) draws one along points'),
                            ...kinds.filter((k) => k.structural).map(kindEntry),
                        ],
                    },
                    {
                        label: 'Openings',
                        note: wallMissing === null ? null : 'Select a wall to put a door or window in it.',
                        entries: Object.entries(OpeningTypeLabels).map(([type, label]) => ({
                            choice: `opening:${type}`,
                            label,
                            icon: OpeningIcons[type as OpeningType],
                            description: `${label} in the selected wall`,
                            blocked: wallMissing,
                        })),
                    },
                    {
                        label: 'Roof & site',
                        note: null,
                        entries: [
                            plain('roof', 'Roof', 'roof', 'A roof over the floor’s walls'),
                            {
                                ...plain('roofWindow', 'Roof window', 'window', 'In the selected pitched roof'),
                                blocked: this.selectedPitchedRoof() === null ? 'Select a pitched roof first' : null,
                            },
                            plain('plot', 'Plot', 'plot', 'The land round the building'),
                            plain('section', 'Section line', 'section', 'A cut through the building for the section drawings'),
                        ],
                    },
                ],
            },
            {
                key: 'fittings',
                label: 'Fittings',
                icon: 'bath',
                groups: [{label: 'Fittings & appliances', note: null, entries: kinds.filter((k) => !k.structural).map(kindEntry)}],
            },
        ];
        const definitionEntry = (d: FurnitureDefinition): AddEntry => {
            const catalogue = catalogueOf(d);
            const icon = catalogue === null ? 'box' : CatalogueIcons[catalogue];
            return {choice: `custom:${d.id}`, label: d.name, icon, description: d.description, blocked: null};
        };
        // The catalogue's furniture by where it is filed, in the catalogues' order, then what is filed nowhere.
        const global = this.editor.definitions().filter((d) => d.scope === 'global');
        for (const [catalogue, label] of Object.entries(CatalogueLabels) as [FurnitureCatalogue, string][]) {
            const entries = global.filter((d) => catalogueOf(d) === catalogue).map(definitionEntry);
            categories.push({key: `furniture-${catalogue}`, label, icon: CatalogueIcons[catalogue], groups: [{label, note: null, entries}]});
        }
        categories.push({
            key: 'furniture-other',
            label: 'Other furniture',
            icon: 'box',
            groups: [{label: 'Other furniture', note: null, entries: global.filter((d) => catalogueOf(d) === null).map(definitionEntry)}],
        });
        const own = this.editor.definitions().filter((d) => d.scope !== 'global');
        const models = this.editor.models().map(
            (m): AddEntry => ({choice: `model:${m.id}`, label: m.name, icon: 'box', description: m.description, blocked: null}),
        );
        categories.push(
            {
                key: 'own',
                label: 'My furniture',
                icon: 'folder',
                groups: [
                    {label: 'This project', note: null, entries: own.filter((d) => d.scope === 'project').map(definitionEntry)},
                    {label: 'My library', note: null, entries: own.filter((d) => d.scope === 'user').map(definitionEntry)},
                ],
            },
            {key: 'models', label: '3D products', icon: 'box', groups: [{label: '3D products', note: null, entries: models}]},
        );
        return categories
            .map((c) => ({...c, groups: c.groups.filter((g) => g.entries.length > 0)}))
            .filter((c) => c.groups.length > 0);
    });
    protected readonly active = computed(() => this.categories().find((c) => c.key === this.activeKey()) ?? this.categories()[0] ?? null);
    /** The active category's groups, or every entry matching the search, by category. */
    protected readonly shown = computed((): AddGroup[] => {
        const query = this.query().trim().toLowerCase();
        if (query === '') {
            return this.active()?.groups ?? [];
        }
        return this.categories().flatMap((category) =>
            category.groups
                .map((group) => ({
                    label: category.label === group.label ? group.label : `${category.label} · ${group.label}`,
                    note: null,
                    entries: group.entries.filter((e) => e.label.toLowerCase().includes(query) || e.description.toLowerCase().includes(query)),
                }))
                .filter((group) => group.entries.length > 0),
        );
    });

    public constructor() {
        queueMicrotask(() => this.search().nativeElement.focus());
    }

    protected count(category: AddCategory): number {
        return category.groups.reduce((sum, g) => sum + g.entries.length, 0);
    }

    /** Enter in the search adds the first match that can be added. */
    protected addFirst(): void {
        const entry = this.shown()
            .flatMap((g) => g.entries)
            .find((e) => e.blocked === null);
        if (entry !== undefined) {
            this.add(entry.choice);
        }
    }

    protected add(choice: string): void {
        const center = this.viewCenter()();
        const room = this.selectedRoom();
        const at = room === null ? center : roundPoint(polygonCentroid(room.polygon));
        this.closed.emit();
        if (choice.startsWith('item:')) {
            void this.editor.create('item', {kind: choice.slice(5), x: at.x, y: at.y, roomId: room?.id ?? null});
        } else if (choice.startsWith('custom:')) {
            void this.editor.create('item', {kind: 'Custom', definitionId: Number(choice.slice(7)), x: at.x, y: at.y, roomId: room?.id ?? null});
        } else if (choice.startsWith('model:')) {
            void this.editor.create('item', {kind: 'Model', modelId: Number(choice.slice(6)), x: at.x, y: at.y, roomId: room?.id ?? null});
        } else if (choice === 'wall') {
            void this.editor.create('wall', {
                startX: center.x - NewWallLength / 2,
                startY: center.y,
                endX: center.x + NewWallLength / 2,
                endY: center.y,
            });
        } else if (choice.startsWith('opening:')) {
            const wall = this.selectedWall();
            if (wall !== null) {
                const type = choice.slice(8) as OpeningType;
                const length = wallLength(wall);
                const width = NewOpeningWidths[type];
                // Centred; a sliding or pocket door opens towards the wall start, so it sits past the middle.
                const offset = type === 'SlidingDoor' || type === 'PocketDoor' ? Math.round((length + width) / 2) : Math.round((length - width) / 2);
                void this.editor.create('opening', {wallId: wall.id, type, offset: Math.max(0, Math.min(offset, length - width))});
            }
        } else if (choice === 'section') {
            // The backend runs it west to east through the middle of the walls, looking north, named by the next letter.
            void this.editor.create('section', {});
        } else if (choice === 'roof') {
            // Its rectangle comes from the backend: round the floor's walls, the ridge along the longer side.
            void this.editor.create('roof', {});
        } else if (choice === 'roofWindow') {
            // The backend puts it low on the roof's front plane.
            const roof = this.selectedPitchedRoof();
            if (roof !== null) {
                void this.editor.create('roofWindow', {roofId: roof.id});
            }
        } else if (choice === 'stair') {
            // The first riser at the view's centre (or the selected room's), walking up north.
            void this.editor.create('stair', {x: at.x, y: at.y});
        } else if (choice === 'void') {
            void this.editor.create('void', {polygon: square(at, NewVoidSize / 2)});
        } else if (choice === 'railing') {
            void this.editor.create('railing', {
                points: [
                    [at.x - NewRailingLength / 2, at.y],
                    [at.x + NewRailingLength / 2, at.y],
                ],
            });
        } else if (choice === 'plot') {
            // Its boundary comes from the backend: a rectangle round the floor's walls and rooms.
            void this.editor.create('plot', {});
        } else if (choice === 'room') {
            void this.editor.create('room', {...newRoomFields(this.scene()), polygon: square(center, NewRoomSize / 2)});
        }
    }
}

function roundPoint(p: {x: number; y: number}): {x: number; y: number} {
    return {x: Math.round(p.x), y: Math.round(p.y)};
}

function square(at: {x: number; y: number}, half: number): [number, number][] {
    return [
        [at.x - half, at.y - half],
        [at.x + half, at.y - half],
        [at.x + half, at.y + half],
        [at.x - half, at.y + half],
    ];
}
