import {ChangeDetectionStrategy, Component} from '@angular/core';
import {RouterOutlet} from '@angular/router';
import {AlertComponent} from '@app/shared/alert/alert.component';

@Component({
    selector: 'fd-app',
    imports: [RouterOutlet, AlertComponent],
    template: '<router-outlet /><fd-alert />',
    changeDetection: ChangeDetectionStrategy.OnPush,
})
export class AppComponent {}
