import {DecimalPipe} from '@angular/common';
import {ChangeDetectionStrategy, Component, computed, effect, inject, input, numberAttribute, signal} from '@angular/core';
import {takeUntilDestroyed, toObservable} from '@angular/core/rxjs-interop';
import {RouterLink} from '@angular/router';
import {originClientId} from '@app/core/interceptors/origin.interceptor';
import {Project} from '@app/models/project';
import {MaterialPrice, MaterialPrices, Quantities, QuantityLine, QuantityMaterial, QuantityRoof, SurfaceLabels} from '@app/models/quantities';
import {AlertService} from '@app/services/alert.service';
import {ProjectService} from '@app/services/project.service';
import {RealtimeService} from '@app/services/realtime.service';
import {filter, switchMap} from 'rxjs';

type PriceField = 'pricePerArea' | 'pricePerLength';

/** What a price is for: a material, or a surface's default look. Mirrors `PriceList::key()`. */
function priceKey(price: Pick<MaterialPrice, 'materialId' | 'surface'>): string {
    return price.materialId === null ? `surface:${price.surface ?? ''}` : `material:${price.materialId}`;
}

/**
 * Quantities and cost estimate of a project (all floors or one): what to buy per material with editable prices, the
 * furniture boards of the cut list, and the surfaces of every room. Prices are the project's own; they save as a whole.
 * The draft keeps only what was typed here (`edits`), over the list as last saved, so prices saved elsewhere meanwhile
 * are kept.
 */
@Component({
    selector: 'fd-quantities',
    imports: [DecimalPipe, RouterLink],
    templateUrl: './quantities.component.html',
    styleUrl: './quantities.component.scss',
    changeDetection: ChangeDetectionStrategy.OnPush,
})
export class QuantitiesComponent {
    public readonly projectId = input.required({transform: numberAttribute});

    private readonly projects = inject(ProjectService);
    private readonly alerts = inject(AlertService);

    protected readonly project = signal<Project | null>(null);
    protected readonly floorId = signal<number | null>(null);
    protected readonly quantities = signal<Quantities | null>(null);
    protected readonly loading = signal(true);
    protected readonly saving = signal(false);
    protected readonly labels = SurfaceLabels;

    /** The price list as last saved, by `priceKey()`. */
    private readonly saved = signal<MaterialPrices>({currency: 'EUR', wastePercent: 10, prices: []});
    /** Prices typed here since, by `priceKey()`; currency and waste when changed. */
    private readonly edits = signal<Map<string, MaterialPrice>>(new Map());
    private readonly currencyEdit = signal<string | null>(null);
    private readonly wasteEdit = signal<number | null>(null);
    /** Price boxes holding text that is not a price (`key:field`). */
    private readonly invalid = signal<Set<string>>(new Set());

    /** The draft: the saved list with the edits on top. */
    protected readonly prices = computed(() => {
        const prices = new Map(this.saved().prices.map((price) => [priceKey(price), price]));
        for (const [key, price] of this.edits()) {
            prices.set(key, price);
        }
        return prices;
    });
    protected readonly currency = computed(() => this.currencyEdit() ?? this.saved().currency);
    protected readonly wastePercent = computed(() => this.wasteEdit() ?? this.saved().wastePercent);
    protected readonly dirty = computed(() => this.edits().size > 0 || this.currencyEdit() !== null || this.wasteEdit() !== null);
    protected readonly currencyValid = computed(() => /^[A-Za-z]{3}$/.test(this.currency().trim()));
    protected readonly wasteValid = computed(() => Number.isInteger(this.wastePercent()) && this.wastePercent() >= 0 && this.wastePercent() <= 100);
    protected readonly valid = computed(() => this.currencyValid() && this.wasteValid() && this.invalid().size === 0);
    /** The materials to buy and the furniture boards, as tables with price inputs. */
    protected readonly tables = computed(() => {
        const quantities = this.quantities();
        return [
            {title: 'Materials to buy', note: 'm² in brackets: whole pieces', rows: quantities?.materials ?? []},
            {title: 'Furniture boards', note: 'board area · edge banding', rows: quantities?.furniture ?? []},
        ].filter((table) => table.rows.length > 0);
    });

    /** Bumped by every quantities load; an older request that comes back late is dropped. */
    private loads = 0;

    public constructor() {
        effect(() => void this.loadProject(this.projectId()));
        effect(() => void this.loadPrices(this.projectId()));
        effect(() => void this.load(this.projectId(), this.floorId()));

        // Edits, catalogue changes, prices and details saved elsewhere (another tab, an agent) show up without a reload.
        const realtime = inject(RealtimeService);
        toObservable(this.projectId)
            .pipe(
                switchMap((projectId) => realtime.projectUpdates(projectId)),
                filter((update) => update.type === 'projectChanged' || update.originClientId !== originClientId),
                takeUntilDestroyed(),
            )
            .subscribe((update) => {
                if (update.type === 'commentsChanged') {
                    return;
                }
                if (update.type === 'detailsChanged') {
                    void this.loadProject(update.projectId);
                    return;
                }
                if (update.type === 'pricesChanged') {
                    void this.loadPrices(update.projectId);
                }
                void this.load(update.projectId, this.floorId());
            });
    }

    protected setFloor(event: Event): void {
        const value = (event.target as HTMLSelectElement).value;
        this.floorId.set(value === '' ? null : Number(value));
    }

    protected surfaces(row: QuantityMaterial): string {
        return row.surfaces.map((surface) => this.labels[surface]).join(', ');
    }

    /** After a roof's name: its floor (with all floors of several shown) and its roof windows. */
    protected roofNote(roof: QuantityRoof): string {
        const floor = this.floorId() === null && (this.project()?.floors?.length ?? 0) > 1 ? ` · ${roof.floorName}` : '';
        const windows = roof.windows > 0 ? ` · ${roof.windows} ${roof.windows === 1 ? 'roof window' : 'roof windows'}` : '';
        return floor + windows;
    }

    protected lineQuantity(line: QuantityLine): string {
        return line.area === null ? `${(line.length ?? 0).toFixed(2)} m` : `${line.area.toFixed(2)} m²`;
    }

    /** The draft price of a row's material (or default look). */
    protected price(row: QuantityMaterial, field: PriceField): number | null {
        return this.prices().get(priceKey(row))?.[field] ?? null;
    }

    protected priceInvalid(row: QuantityMaterial, field: PriceField): boolean {
        return this.invalid().has(`${priceKey(row)}:${field}`);
    }

    protected setPrice(row: QuantityMaterial, field: PriceField, event: Event): void {
        const text = (event.target as HTMLInputElement).value.trim().replace(',', '.');
        const value = text === '' ? null : Number(text);
        const key = priceKey(row);

        const invalid = new Set(this.invalid());
        if (value !== null && (!Number.isFinite(value) || value < 0)) {
            invalid.add(`${key}:${field}`);
            this.invalid.set(invalid);
            return;
        }
        invalid.delete(`${key}:${field}`);
        this.invalid.set(invalid);

        const edits = new Map(this.edits());
        const current = this.prices().get(key) ?? {materialId: row.materialId, surface: row.surface, pricePerArea: null, pricePerLength: null};
        edits.set(key, {...current, [field]: value});
        this.edits.set(edits);
    }

    protected setCurrency(event: Event): void {
        this.currencyEdit.set((event.target as HTMLInputElement).value);
    }

    protected setWaste(event: Event): void {
        const text = (event.target as HTMLInputElement).value.trim();
        this.wasteEdit.set(text === '' ? NaN : Number(text));
    }

    protected async save(): Promise<void> {
        if (!this.valid()) {
            return;
        }
        this.saving.set(true);
        try {
            const saved = await this.projects.saveMaterialPrices(this.projectId(), {
                currency: this.currency().trim().toUpperCase(),
                wastePercent: this.wastePercent(),
                prices: [...this.prices().values()].filter((p) => p.pricePerArea !== null || p.pricePerLength !== null),
            });
            this.applyPrices(saved);
            this.discard();
            this.alerts.success('Prices saved.');
            await this.load(this.projectId(), this.floorId());
        } catch {
            // The error interceptor shows the toast.
        } finally {
            this.saving.set(false);
        }
    }

    protected discard(): void {
        this.edits.set(new Map());
        this.currencyEdit.set(null);
        this.wasteEdit.set(null);
        this.invalid.set(new Set());
    }

    protected print(): void {
        window.print();
    }

    private applyPrices(prices: MaterialPrices): void {
        this.saved.set(prices);
    }

    private async loadProject(projectId: number): Promise<void> {
        try {
            this.project.set(await this.projects.get(projectId));
        } catch {
            // The error interceptor shows the toast.
        }
    }

    private async loadPrices(projectId: number): Promise<void> {
        try {
            this.applyPrices(await this.projects.materialPrices(projectId));
        } catch {
            // The error interceptor shows the toast.
        }
    }

    private async load(projectId: number, floorId: number | null): Promise<void> {
        const load = ++this.loads;
        this.loading.set(true);
        try {
            const quantities = await this.projects.quantities(projectId, floorId ?? undefined);
            if (load === this.loads) {
                this.quantities.set(quantities);
            }
        } catch {
            // The error interceptor shows the toast.
        } finally {
            if (load === this.loads) {
                this.loading.set(false);
            }
        }
    }
}
