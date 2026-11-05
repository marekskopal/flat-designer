import {ChangeDetectionStrategy, Component, input} from '@angular/core';
import {WebglProblem} from '@app/viewer/engine/webgl';
import {TranslatePipe} from '@ngx-translate/core';

/** Shown in place of a 3D view the browser cannot draw: why, and what usually helps. */
@Component({
    selector: 'fd-webgl-message',
    imports: [TranslatePipe],
    template: `
        <div class="message" role="alert">
            @if (problem() === 'lost') {
                <strong>{{ 'viewer.webgl.lostTitle' | translate }}</strong>
                <p>{{ 'viewer.webgl.lostText' | translate }}</p>
                <button type="button" class="btn btn--primary btn--small" (click)="reload()">{{ 'viewer.webgl.reload' | translate }}</button>
            } @else {
                <strong>{{ 'viewer.webgl.missingTitle' | translate }}</strong>
                <p>{{ 'viewer.webgl.missingText' | translate }}</p>
                <p><a href="https://get.webgl.org/webgl2/" target="_blank" rel="noopener">{{ 'viewer.webgl.check' | translate }}</a></p>
            }
        </div>
    `,
    styles: `
        @use 'variables' as *;

        :host {
            position: absolute;
            inset: 0;
            display: grid;
            place-items: center;
            padding: $space-16;
        }

        .message {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: $space-8;
            max-width: 420px;
            padding: $space-20;
            border: 1px solid $color-border;
            border-radius: $radius-14;
            background: $color-surface-solid;
            font-size: $font-size-13;

            p {
                margin: 0;
                color: $color-text-muted;
            }
        }
    `,
    changeDetection: ChangeDetectionStrategy.OnPush,
})
export class WebglMessageComponent {
    public readonly problem = input.required<WebglProblem>();

    protected reload(): void {
        window.location.reload();
    }
}
