import {ChangeDetectionStrategy, Component, input} from '@angular/core';
import {WebglProblem} from '@app/viewer/engine/webgl';

/** Shown in place of a 3D view the browser cannot draw: why, and what usually helps. */
@Component({
    selector: 'fd-webgl-message',
    template: `
        <div class="message" role="alert">
            @if (problem() === 'lost') {
                <strong>The 3D view stopped</strong>
                <p>The graphics card was reset or ran out of memory. Reload the page to draw it again.</p>
                <button type="button" class="btn btn--primary btn--small" (click)="reload()">Reload</button>
            } @else {
                <strong>This browser cannot show the 3D view</strong>
                <p>
                    Izbik draws with WebGL 2, which is off or missing here. Turn on hardware acceleration in the browser's settings, update the
                    browser and the graphics driver, or try a current Chrome, Firefox, Edge or Safari.
                </p>
                <p><a href="https://get.webgl.org/webgl2/" target="_blank" rel="noopener">Check WebGL 2 in this browser</a></p>
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
