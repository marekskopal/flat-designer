// Mirrors the backend DTOs (FlatDesigner\Dto\Comment*).

/** Who wrote a comment: a user in the app, an agent over MCP or a share link's guest. */
export type CommentAuthorType = 'User' | 'Agent' | 'Guest';

/** A review comment pinned to a point of a floor (plan mm, z above the floor); a reply has the thread's `parentId` and point. */
export interface Comment {
    id: number;
    floorId: number;
    parentId: number | null;
    /** The thread's number on its pin (counted up per floor, the same for everyone); null on a reply. */
    number: number | null;
    x: number;
    y: number;
    z: number;
    body: string;
    authorType: CommentAuthorType;
    authorName: string;
    /** Set on threads only. */
    resolved: boolean;
    createdAt: string;
    updatedAt: string;
    /** For the reader: the author edits the text; a person deletes any comment, an agent its own. Never for guests. */
    canEdit: boolean;
    canDelete: boolean;
}

/** A new thread (with its point) or a reply (`parentId`). */
export interface CommentInput {
    floorId?: number;
    parentId?: number | null;
    x?: number;
    y?: number;
    z?: number;
    body: string;
    /** A share link's guest signs with a name. */
    guestName?: string;
}

/** A thread with its replies. */
export interface CommentThread {
    /** The pin's number: the thread's own. */
    number: number;
    comment: Comment;
    replies: Comment[];
}

/** The threads of a list (threads first in it, each followed by its replies). */
export function commentThreads(comments: readonly Comment[]): CommentThread[] {
    const threads: CommentThread[] = [];
    const byId = new Map<number, CommentThread>();
    for (const comment of comments) {
        if (comment.parentId === null) {
            const thread = {number: comment.number ?? threads.length + 1, comment, replies: []};
            threads.push(thread);
            byId.set(comment.id, thread);
        } else {
            byId.get(comment.parentId)?.replies.push(comment);
        }
    }
    return threads;
}

/** A pin drawn in the view (the engine's `CommentPin`): a thread's number at its point, or the draft of a new thread. */
export interface CommentPinData extends CommentPoint {
    id: number | null;
    label: string;
    resolved: boolean;
}

/** A point of the floor: plan mm, z above the floor. */
export interface CommentPoint {
    x: number;
    y: number;
    z: number;
}

/** The pins to draw: the open threads, a resolved one while it is open in the panel (`activeId`), and a new thread's point. */
export function commentPinsOf(comments: readonly Comment[], activeId: number | null, draft: CommentPoint | null): CommentPinData[] {
    const pins: CommentPinData[] = commentThreads(comments)
        .filter((t) => !t.comment.resolved || t.comment.id === activeId)
        .map((t) => ({id: t.comment.id, label: String(t.number), x: t.comment.x, y: t.comment.y, z: t.comment.z, resolved: t.comment.resolved}));
    return draft === null ? pins : [...pins, {id: null, label: '+', ...draft, resolved: false}];
}
