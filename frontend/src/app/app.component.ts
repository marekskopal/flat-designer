import {DecimalPipe} from '@angular/common';
import {ChangeDetectionStrategy, Component, computed, DestroyRef, inject, signal, viewChild} from '@angular/core';
import {takeUntilDestroyed} from '@angular/core/rxjs-interop';
import {Room, Scene} from '@app/models/scene';
import {SceneApiService} from '@app/services/scene-api.service';
import {ViewPreset} from '@app/viewer/engine/viewer-engine';
import {ViewerComponent} from '@app/viewer/viewer.component';
import {switchMap} from 'rxjs';

/** Section heights offered by the slider (mm); the top value means "no cut". */
const SectionMin = 900;
const SectionMax = 2700;
/** Plan view cuts walls at this height, like an architectural floor plan. */
const PlanSectionHeight = 1300;

interface ViewOption {
    preset: ViewPreset;
    label: string;
}

@Component({
    selector: 'fd-app',
    imports: [ViewerComponent, DecimalPipe],
    templateUrl: './app.component.html',
    styleUrl: './app.component.scss',
    changeDetection: ChangeDetectionStrategy.OnPush,
})
export class AppComponent {
    /** Panel width plus its margins, so the 3D view frames the model beside it. */
    protected readonly panelInset = 332;
    protected readonly sectionMin = SectionMin;
    protected readonly sectionMax = SectionMax;
    protected readonly views: ViewOption[] = [
        {preset: 'overview', label: '3D'},
        {preset: 'top', label: 'Plan'},
        {preset: 'kitchen', label: 'Kitchen'},
    ];

    protected readonly scene = signal<Scene | null>(null);
    protected readonly error = signal<string | null>(null);
    protected readonly activeView = signal<ViewPreset>('overview');
    protected readonly ceilingsVisible = signal(false);
    protected readonly labelsVisible = signal(true);
    protected readonly sectionSlider = signal(SectionMax);
    protected readonly selectedRoomId = signal<number | null>(null);

    protected readonly sectionHeight = computed(() => (this.sectionSlider() >= SectionMax ? null : this.sectionSlider()));
    protected readonly rooms = computed(() => this.scene()?.rooms ?? []);
    protected readonly indoorArea = computed(() => this.rooms().filter((r) => r.type !== 'Terrace').reduce((sum, r) => sum + r.area, 0));
    protected readonly totalArea = computed(() => this.rooms().reduce((sum, r) => sum + r.area, 0));

    private readonly viewer = viewChild.required(ViewerComponent);

    public constructor() {
        const api = inject(SceneApiService);
        api.getFlats()
            .pipe(
                switchMap((flats) => {
                    if (flats.length === 0) {
                        throw new Error('No flat in the database. Did the migrations run?');
                    }
                    return api.getScene(flats[0].id);
                }),
                takeUntilDestroyed(inject(DestroyRef)),
            )
            .subscribe({
                next: (scene) => this.scene.set(scene),
                error: (e: unknown) => this.error.set(e instanceof Error ? e.message : 'Could not load the flat.'),
            });
    }

    protected setView(preset: ViewPreset): void {
        this.activeView.set(preset);
        if (preset !== 'kitchen') {
            this.labelsVisible.set(true);
        }
        if (preset === 'top') {
            this.ceilingsVisible.set(false);
            this.sectionSlider.set(PlanSectionHeight);
        } else if (preset === 'kitchen') {
            // Eye level: a closed room, without labels floating through the walls.
            this.ceilingsVisible.set(true);
            this.labelsVisible.set(false);
            this.sectionSlider.set(SectionMax);
        } else {
            this.ceilingsVisible.set(false);
            this.sectionSlider.set(SectionMax);
        }
        this.viewer().setView(preset);
    }

    protected selectRoom(room: Room): void {
        this.selectedRoomId.set(room.id);
        this.ceilingsVisible.set(false);
        this.labelsVisible.set(true);
        this.activeView.set('overview');
        this.viewer().focusRoom(room);
    }

    protected onRoomPicked(roomId: number | null): void {
        this.selectedRoomId.set(roomId);
    }

    protected onSectionInput(event: Event): void {
        this.sectionSlider.set(Number((event.target as HTMLInputElement).value));
    }
}
