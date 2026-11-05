import {ChangeDetectionStrategy, Component, effect, ElementRef, inject, input, output, viewChild} from '@angular/core';
import {LanguageService} from '@app/services/language.service';

const ScriptUrl = 'https://accounts.google.com/gsi/client';
/** Google draws its button at most this wide. */
const MaxButtonWidth = 400;

let scriptLoad: Promise<void> | null = null;

/** Loads Google Identity Services once, on the first page that shows the button. */
function loadScript(): Promise<void> {
    scriptLoad ??= new Promise<void>((resolve, reject) => {
        const script = document.createElement('script');
        script.src = ScriptUrl;
        script.async = true;
        script.onload = (): void => resolve();
        script.onerror = (): void => {
            scriptLoad = null;
            script.remove();
            reject(new Error('Google Identity Services did not load.'));
        };
        document.head.appendChild(script);
    });
    return scriptLoad;
}

/** Google's "Sign in with Google" button; emits the ID token for `AuthenticationService.googleLogin()`. */
@Component({
    selector: 'fd-google-sign-in',
    template: '<div #button class="google-button" [class.google-button--busy]="busy()"></div>',
    styles: `
        :host {
            display: block;
        }
        .google-button {
            display: flex;
            justify-content: center;
            min-height: 44px;
        }
        .google-button--busy {
            opacity: 0.5;
            pointer-events: none;
        }
    `,
    changeDetection: ChangeDetectionStrategy.OnPush,
})
export class GoogleSignInComponent {
    public readonly clientId = input.required<string>();
    public readonly text = input<'signin_with' | 'signup_with'>('signin_with');
    public readonly busy = input(false);
    public readonly credential = output<string>();
    public readonly unavailable = output<void>();

    private readonly button = viewChild.required<ElementRef<HTMLElement>>('button');
    private readonly language = inject(LanguageService);

    public constructor() {
        effect(() => {
            void this.render(this.clientId(), this.text(), this.language.current(), this.button().nativeElement);
        });
    }

    private async render(clientId: string, text: 'signin_with' | 'signup_with', locale: string, container: HTMLElement): Promise<void> {
        try {
            await loadScript();
        } catch {
            this.unavailable.emit();
            return;
        }
        const id = window.google?.accounts?.id;
        if (id === undefined) {
            this.unavailable.emit();
            return;
        }
        id.initialize({
            client_id: clientId,
            callback: (response) => this.credential.emit(response.credential),
            use_fedcm_for_button: true,
        });
        id.renderButton(container, {
            type: 'standard',
            theme: 'outline',
            size: 'large',
            text,
            shape: 'rectangular',
            logo_alignment: 'center',
            locale,
            width: Math.min(MaxButtonWidth, container.offsetWidth),
        });
    }
}
