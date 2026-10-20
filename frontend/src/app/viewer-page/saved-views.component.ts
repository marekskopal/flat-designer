import {ChangeDetectionStrategy, Component, input, output, signal} from '@angular/core';
import {SavedView} from '@app/models/saved-view';

/** Where a captured PNG goes: downloaded, or saved among the project's files. */
export type CaptureTarget = 'download' | 'file';

/**
 * The saved views of the project in the viewer's panel: apply one (on its floor), save the current view under a name
 * (a name in use is replaced), update or delete one, and capture the current view as a PNG.
 */
@Component({
    selector: 'fd-saved-views',
    templateUrl: './saved-views.component.html',
    styleUrl: './saved-views.component.scss',
    changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SavedViewsComponent {
    public readonly views = input.required<SavedView[]>();
    /** The floor shown: views of other floors say which floor they show. */
    public readonly floorId = input.required<number>();
    public readonly busy = input(false);
    /** Saving needs the working copy (a saved version is read-only). */
    public readonly canSave = input(true);

    public readonly apply = output<SavedView>();
    public readonly save = output<string>();
    public readonly overwrite = output<SavedView>();
    public readonly remove = output<SavedView>();
    public readonly capture = output<CaptureTarget>();

    protected readonly name = signal('');

    protected submit(event: Event): void {
        event.preventDefault();
        const name = this.name().trim();
        if (name !== '') {
            this.save.emit(name);
            this.name.set('');
        }
    }
}
