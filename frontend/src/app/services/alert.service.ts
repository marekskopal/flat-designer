import {Injectable, signal} from '@angular/core';

export type AlertKind = 'success' | 'error';

export interface Alert {
    id: number;
    kind: AlertKind;
    message: string;
}

/** Toasts shown by `AlertComponent`; they dismiss themselves after a few seconds. */
@Injectable({providedIn: 'root'})
export class AlertService {
    public readonly alerts = signal<Alert[]>([]);

    private nextId = 1;

    public success(message: string): void {
        this.push('success', message);
    }

    public error(message: string): void {
        this.push('error', message);
    }

    public dismiss(id: number): void {
        this.alerts.update((all) => all.filter((a) => a.id !== id));
    }

    private push(kind: AlertKind, message: string): void {
        const id = this.nextId++;
        this.alerts.update((all) => [...all, {id, kind, message}]);
        setTimeout(() => this.dismiss(id), kind === 'error' ? 6000 : 3500);
    }
}
