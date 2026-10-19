import {provideHttpClient} from '@angular/common/http';
import {HttpTestingController, provideHttpClientTesting} from '@angular/common/http/testing';
import {provideZonelessChangeDetection} from '@angular/core';
import {TestBed} from '@angular/core/testing';
import {ProjectUpdate} from '@app/models/realtime';
import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';

import {RealtimeService} from './realtime.service';

/** Stand-in for the browser's EventSource (jsdom has none). */
class FakeEventSource {
    public static readonly CONNECTING = 0;
    public static readonly OPEN = 1;
    public static readonly CLOSED = 2;
    public static instances: FakeEventSource[] = [];

    public readyState = FakeEventSource.OPEN;
    public onmessage: ((event: MessageEvent<string>) => void) | null = null;
    public onerror: (() => void) | null = null;

    public constructor(public readonly url: string, public readonly init?: EventSourceInit) {
        FakeEventSource.instances.push(this);
    }

    public emit(data: unknown): void {
        this.onmessage?.({data: JSON.stringify(data)} as MessageEvent<string>);
    }

    public close(): void {
        this.readyState = FakeEventSource.CLOSED;
    }
}

const update = (projectId: number, revision: number): ProjectUpdate => ({
    type: 'projectChanged',
    projectId,
    revision,
    floorId: 3,
    summary: 'Moved item "Base"',
    undone: false,
    actorType: 'Agent',
    actorName: 'Claude',
    originClientId: null,
});

async function settle(): Promise<void> {
    await new Promise<void>((resolve) => setTimeout(resolve, 0));
}

describe('RealtimeService', () => {
    beforeEach(() => {
        FakeEventSource.instances = [];
        vi.stubGlobal('EventSource', FakeEventSource);
        TestBed.configureTestingModule({
            providers: [provideZonelessChangeDetection(), provideHttpClient(), provideHttpClientTesting()],
        });
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.unstubAllGlobals();
        TestBed.inject(HttpTestingController).verify();
        TestBed.resetTestingModule();
    });

    function subscribeHub(): void {
        TestBed.inject(HttpTestingController)
            .expectOne({method: 'POST', url: '/api/realtime/subscription'})
            .flush({hubUrl: '/.well-known/mercure', topicPrefix: 'flat-designer/projects/'});
    }

    it('gets the hub cookie, then listens to the project topic', async () => {
        const received: ProjectUpdate[] = [];
        const subscription = TestBed.inject(RealtimeService).projectUpdates(7).subscribe((u) => received.push(u));

        subscribeHub();
        await settle();

        const source = FakeEventSource.instances[0];
        expect(source.url).toBe('/.well-known/mercure?match=flat-designer%2Fprojects%2F7');
        expect(source.init).toEqual({withCredentials: true});

        source.emit(update(7, 12));
        source.emit(update(8, 13));
        source.onmessage?.({data: 'not json'} as MessageEvent<string>);
        const definitionChange = {type: 'definitionChanged', projectId: 7, definitionId: 4, originClientId: null} as const;
        source.emit(definitionChange);
        const materialChange = {type: 'materialChanged', projectId: 7, materialId: 3, originClientId: null} as const;
        source.emit(materialChange);
        source.emit({type: 'somethingElse', projectId: 7});
        expect(received).toEqual([update(7, 12), definitionChange, materialChange]);

        subscription.unsubscribe();
        expect(source.readyState).toBe(FakeEventSource.CLOSED);
    });

    it('subscribes again with a fresh cookie when the hub closes the stream', async () => {
        vi.useFakeTimers();
        const subscription = TestBed.inject(RealtimeService).projectUpdates(7).subscribe();
        subscribeHub();
        await vi.advanceTimersByTimeAsync(0);

        const first = FakeEventSource.instances[0];
        first.readyState = FakeEventSource.CLOSED;
        first.onerror?.();
        await vi.advanceTimersByTimeAsync(3000);

        subscribeHub();
        await vi.advanceTimersByTimeAsync(0);
        expect(FakeEventSource.instances).toHaveLength(2);

        subscription.unsubscribe();
    });
});
