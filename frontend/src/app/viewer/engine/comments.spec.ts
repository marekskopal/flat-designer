import * as THREE from 'three';
import {describe, expect, it} from 'vitest';

import {CommentPins, commentPointOf, pinPosition} from './comments';

describe('comment pins', () => {
    it('stand at the plan point, plan y being scene -z', () => {
        const position = pinPosition({x: 1200, y: 900, z: 850});
        expect([position.x, position.y, position.z]).toEqual([1.2, 0.85, -0.9]);
    });

    it('turn a clicked scene point back into whole plan mm, not below the floor', () => {
        expect(commentPointOf(new THREE.Vector3(1.2004, 0.8496, -0.9)))
            .toEqual({x: 1200, y: 900, z: 850});
        expect(commentPointOf(new THREE.Vector3(0, -0.01, 0)).z).toBe(0);
    });

    it('mark the active, resolved, draft and cut-away pins and report clicks', () => {
        const pins = new CommentPins();
        const picked: number[] = [];
        pins.onPick = (id): number => picked.push(id);
        pins.setSectionHeight(1300);
        pins.set(
            [
                {id: 7, label: '1', x: 0, y: 0, z: 0, resolved: false},
                {id: 8, label: '2', x: 0, y: 0, z: 2400, resolved: true},
                {id: null, label: '+', x: 0, y: 0, z: 0, resolved: false},
            ],
            7,
        );
        const elements = pins.group.children.map((o) => (o as unknown as {element: HTMLElement}).element);
        expect(elements.map((e) => e.className)).toEqual([
            'comment-pin comment-pin--active',
            'comment-pin comment-pin--resolved comment-pin--cut',
            'comment-pin comment-pin--draft',
        ]);
        elements[1].click();
        elements[2].click();
        expect(picked).toEqual([8]);

        pins.setSectionHeight(null);
        expect(elements[1].className).toBe('comment-pin comment-pin--resolved');
        pins.setVisible(false);
        expect(pins.group.children.every((o) => !o.visible)).toBe(true);
        pins.clear();
        expect(pins.group.children).toHaveLength(0);
    });
});
