import {ChangeDetectionStrategy, Component, inject} from '@angular/core';
import {AlertService} from '@app/services/alert.service';
import {TranslatePipe} from '@ngx-translate/core';

@Component({
    selector: 'fd-alert',
    imports: [TranslatePipe],
    template: `
        <div class="alerts" aria-live="polite">
            @for (alert of alerts.alerts(); track alert.id) {
                <div class="alert" [class.alert--error]="alert.kind === 'error'" role="status">
                    <span>{{ alert.message }}</span>
                    <button
                        type="button"
                        class="alert__close"
                        [attr.aria-label]="'common.dismiss' | translate"
                        (click)="alerts.dismiss(alert.id)"
                    >
                        ×
                    </button>
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
