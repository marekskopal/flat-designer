import {DatePipe} from '@angular/common';
import {ChangeDetectionStrategy, Component, computed, input, output, signal} from '@angular/core';
import {Comment, CommentThread, commentThreads} from '@app/models/comment';

/** What the panel asks the page to do; the page talks to the server (as the user or a share link's guest). */
export type CommentAction =
    | {kind: 'add'; body: string}
    | {kind: 'reply'; parentId: number; body: string}
    | {kind: 'edit'; comment: Comment; body: string}
    | {kind: 'resolve'; comment: Comment; resolved: boolean}
    | {kind: 'delete'; comment: Comment};

/**
 * The review comments of the shown floor in a viewer's panel: the threads numbered as their pins, with their replies.
 * "Add comment" turns on the comment tool; once a point is clicked (`placing` set), the form for the new thread opens.
 * Clicking a thread selects it and turns the camera to its pin.
 */
@Component({
    selector: 'fd-comments',
    imports: [DatePipe],
    templateUrl: './comments.component.html',
    styleUrl: './comments.component.scss',
    changeDetection: ChangeDetectionStrategy.OnPush,
})
export class CommentsComponent {
    public readonly comments = input.required<Comment[]>();
    public readonly activeId = input<number | null>(null);
    /** New threads and replies may be written (not on a saved version, not on a read-only share link). */
    public readonly canWrite = input(true);
    /** Resolving needs a project member; guests only add. */
    public readonly canResolve = input(true);
    /** The comment tool is on: the next click in the view picks the point. */
    public readonly picking = input(false);
    /** A point was picked for a new thread: the form is open. */
    public readonly placing = input(false);
    public readonly busy = input(false);

    public readonly opened = output<number | null>();
    public readonly pick = output<boolean>();
    public readonly action = output<CommentAction>();

    protected readonly showResolved = signal(false);
    protected readonly draft = signal('');
    protected readonly reply = signal('');
    protected readonly editing = signal<{id: number; body: string} | null>(null);

    protected readonly threads = computed(() => commentThreads(this.comments()));
    protected readonly shown = computed(() => this.threads().filter((t) => this.showResolved() || !t.comment.resolved));
    protected readonly resolvedCount = computed(() => this.threads().filter((t) => t.comment.resolved).length);

    protected toggle(thread: CommentThread): void {
        this.reply.set('');
        this.editing.set(null);
        this.opened.emit(this.activeId() === thread.comment.id ? null : thread.comment.id);
    }

    protected submitDraft(event: Event): void {
        event.preventDefault();
        const body = this.draft().trim();
        if (body !== '') {
            this.action.emit({kind: 'add', body});
            this.draft.set('');
        }
    }

    protected submitReply(event: Event, thread: CommentThread): void {
        event.preventDefault();
        const body = this.reply().trim();
        if (body !== '') {
            this.action.emit({kind: 'reply', parentId: thread.comment.id, body});
            this.reply.set('');
        }
    }

    protected submitEdit(event: Event, comment: Comment): void {
        event.preventDefault();
        const body = this.editing()?.body.trim() ?? '';
        if (body !== '' && body !== comment.body) {
            this.action.emit({kind: 'edit', comment, body});
        }
        this.editing.set(null);
    }

    protected remove(comment: Comment): void {
        const what = comment.parentId === null ? 'this comment and its replies' : 'this reply';
        if (window.confirm(`Delete ${what}?`)) {
            this.action.emit({kind: 'delete', comment});
        }
    }

    protected value(event: Event): string {
        return (event.target as HTMLTextAreaElement).value;
    }
}
