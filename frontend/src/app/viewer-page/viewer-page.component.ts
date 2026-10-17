import {DecimalPipe} from '@angular/common';
import {HttpErrorResponse} from '@angular/common/http';
import {
    ChangeDetectionStrategy,
    Component,
    computed,
    effect,
    inject,
    input,
    numberAttribute,
    resource,
    signal,
    viewChild,
} from '@angular/core';
import {RouterLink} from '@angular/router';
import {errorMessage} from '@app/core/interceptors/error.interceptor';
import {Room, RoomType} from '@app/models/scene';
import {ProjectService} from '@app/services/project.service';
import {ViewPreset} from '@app/viewer/engine/viewer-engine';
import {ViewerComponent} from '@app/viewer/viewer.component';

/** Section heights offered by the slider (mm); the top value means "no cut". */
const SectionMin = 900;
const SectionMax = 2700;
/** Plan view cuts walls at this height, like an architectural floor plan. */
const PlanSectionHeight = 1300;

interface ViewOption {
    preset: ViewPreset;
    label: string;
}

const optionalId = (value: string | number | null | undefined): number | null =>
    value === null || value === undefined || value === '' ? null : Number(value);

/**
 * 3D view of one floor of a project (route `projects/:projectId/floors/:floorId`). With `?version=<id>`
 * it shows that saved version read-only instead of the working copy.
 */
@Component({
    selector: 'fd-viewer-page',
    imports: [ViewerComponent, DecimalPipe, RouterLink],
    templateUrl: './viewer-page.component.html',
    styleUrl: './viewer-page.component.scss',
    changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ViewerPageComponent {
    public readonly projectId = input.required({transform: numberAttribute});
    public readonly floorId = input.required({transform: numberAttribute});
    /** Id of the saved version to show (query parameter), null for the working copy. */
    public readonly version = input(null, {transform: optionalId});

    /** Panel width plus its margins, so the 3D view frames the model beside it. */
    protected readonly panelInset = 332;
    protected readonly sectionMin = SectionMin;
    protected readonly sectionMax = SectionMax;
    protected readonly views: ViewOption[] = [
        {preset: 'overview', label: '3D'},
        {preset: 'top', label: 'Plan'},
        {preset: 'kitchen', label: 'Kitchen'},
    ];

    private readonly projects = inject(ProjectService);

    /** Kept while another floor loads, so the switch does not flash an empty view. */
    private readonly data = resource({
        params: () => ({projectId: this.projectId(), floorId: this.floorId(), versionId: this.version()}),
        loader: async ({params}) => {
            const [project, version, scene] = await Promise.all([
                this.projects.get(params.projectId),
                params.versionId === null ? null : this.projects.getVersion(params.projectId, params.versionId),
                this.projects.getScene(params.projectId, params.floorId, params.versionId),
            ]);
            return {project, version, scene};
        },
    });

    protected readonly scene = computed(() => (this.data.hasValue() ? this.data.value().scene : null));
    protected readonly project = computed(() => (this.data.hasValue() ? this.data.value().project : null));
    protected readonly viewedVersion = computed(() => (this.data.hasValue() ? this.data.value().version : null));
    /** Floors to switch between: those of the viewed version, or of the working copy. */
    protected readonly floors = computed(() => this.viewedVersion()?.floors ?? this.project()?.floors ?? []);
    /** Where "back to current" leads: the same floor when the working copy still has it. */
    protected readonly currentLink = computed(() => {
        const project = this.project();
        if (project === null) {
            return null;
        }
        const floor = project.floors.find((f) => f.id === this.floorId()) ?? project.floors[0];
        return ['/projects', project.id, 'floors', floor.id];
    });
    protected readonly error = computed(() => {
        const error = this.data.error();
        if (error === undefined) {
            return null;
        }
        return error instanceof HttpErrorResponse ? errorMessage(error) : 'Could not load the floor.';
    });
    protected readonly activeView = signal<ViewPreset>('overview');
    protected readonly ceilingsVisible = signal(false);
    protected readonly labelsVisible = signal(true);
    protected readonly sectionSlider = signal(SectionMax);
    protected readonly selectedRoomId = signal<number | null>(null);

    protected readonly sectionHeight = computed(() => (this.sectionSlider() >= SectionMax ? null : this.sectionSlider()));
    protected readonly rooms = computed(() => this.scene()?.rooms ?? []);
    protected readonly indoorArea = computed(() => this.areaOf((type) => type !== 'Terrace' && type !== 'Garden'));
    protected readonly terraceArea = computed(() => this.areaOf((type) => type === 'Terrace'));
    protected readonly gardenArea = computed(() => this.areaOf((type) => type === 'Garden'));

    private readonly viewer = viewChild.required(ViewerComponent);

    public constructor() {
        // A different floor has different rooms.
        effect(() => {
            this.scene();
            this.selectedRoomId.set(null);
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

    private areaOf(matches: (type: RoomType) => boolean): number {
        return this.rooms().filter((r) => matches(r.type)).reduce((sum, r) => sum + r.area, 0);
    }

    protected onSectionInput(event: Event): void {
        this.sectionSlider.set(Number((event.target as HTMLInputElement).value));
    }
}
