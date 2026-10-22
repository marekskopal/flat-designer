import {describe, expect, it} from 'vitest';

import {Comment, commentPinsOf, commentThreads} from './comment';

const comment = (id: number, parentId: number | null, number: number | null, resolved = false): Comment => ({
    id,
    floorId: 1,
    parentId,
    number,
    x: id * 100,
    y: 0,
    z: 0,
    body: `#${id}`,
    authorType: 'User',
    authorName: 'Jane',
    resolved,
    createdAt: '',
    updatedAt: '',
    canEdit: true,
    canDelete: true,
});

describe('comment threads', () => {
    it('group replies under their threads, numbered as the server numbers them', () => {
        const threads = commentThreads([comment(1, null, 1, true), comment(2, 1, null), comment(5, null, 3), comment(6, 5, null)]);
        expect(threads.map((t) => [t.number, t.comment.id, t.replies.map((r) => r.id)])).toEqual([
            [1, 1, [2]],
            [3, 5, [6]],
        ]);
    });

    it('pin the open threads, the active resolved one and the draft', () => {
        const comments = [comment(1, null, 1, true), comment(5, null, 2), comment(6, 5, null)];
        expect(commentPinsOf(comments, null, null).map((p) => p.label)).toEqual(['2']);
        expect(commentPinsOf(comments, 1, null).map((p) => [p.label, p.resolved])).toEqual([
            ['1', true],
            ['2', false],
        ]);
        expect(commentPinsOf(comments, null, {x: 1, y: 2, z: 3}).at(-1)).toEqual({id: null, label: '+', x: 1, y: 2, z: 3, resolved: false});
    });
});
