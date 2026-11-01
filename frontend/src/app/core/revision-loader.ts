import {effect, Signal, signal} from '@angular/core';

/**
 * A signal holding what `loader` returns for the project, loaded again whenever `projectId` or `revision` changes; a
 * request overtaken by a later one is dropped when it comes back. A failed load keeps the last value (the error
 * interceptor has told the user). Call it in an injection context, e.g. a field initializer of a component.
 */
export function revisionLoader<T>(projectId: Signal<number>, revision: Signal<number>, loader: (projectId: number) => Promise<T>): Signal<T | null> {
    const value = signal<T | null>(null);
    let loads = 0;
    effect(() => {
        revision();
        const id = projectId();
        const load = ++loads;
        loader(id).then(
            (result) => {
                if (load === loads) {
                    value.set(result);
                }
            },
            () => undefined,
        );
    });
    return value.asReadonly();
}
