import {ChangeDetectionStrategy, Component, inject} from '@angular/core';
import {AlertService} from '@app/services/alert.service';

@Component({
    selector: 'fd-alert',
    template: `
        <div class="alerts" aria-live="polite">
            @for (alert of alerts.alerts(); track alert.id) {
                <div class="alert" [class.alert--error]="alert.kind === 'error'" role="status">
                    <span>{{ alert.message }}</span>
                    <button type="button" class="alert__close" aria-label="Dismiss" (click)="alerts.dismiss(alert.id)">×</button>
                </div>
            }
        </div>
    `,
    styleUrl: './alert.component.scss',
    changeDetection: ChangeDetectionStrategy.OnPush,
})
export class AlertComponent {
    protected readonly alerts = inject(AlertService);
}
