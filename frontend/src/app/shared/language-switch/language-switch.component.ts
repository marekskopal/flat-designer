import {ChangeDetectionStrategy, Component, inject} from '@angular/core';
import {Locale, LocaleNames, Locales} from '@app/core/locale';
import {AuthenticationService} from '@app/services/authentication.service';
import {LanguageService} from '@app/services/language.service';
import {TranslatePipe} from '@ngx-translate/core';

/**
 * The languages as links, for pages without an account (sign-in, sign-up); the choice stays on the device. Hidden while
 * signed in (verify-email), where the account's language wins.
 */
@Component({
    selector: 'fd-language-switch',
    imports: [TranslatePipe],
    template: `
        @if (!auth.isLoggedIn()) {
            <nav class="language-switch" [attr.aria-label]="'common.language' | translate">
                @for (locale of locales; track locale) {
                    <button
                        type="button"
                        class="language-switch__option"
                        [class.language-switch__option--active]="language.current() === locale"
                        [attr.aria-pressed]="language.current() === locale"
                        [attr.lang]="locale"
                        (click)="choose(locale)"
                    >
                        {{ names[locale] }}
                    </button>
                }
            </nav>
        }
    `,
    styles: `
        @use 'variables' as *;

        .language-switch {
            display: flex;
            justify-content: center;
            gap: $space-12;
            margin-top: $space-20;
        }

        .language-switch__option {
            padding: 0;
            border: 0;
            background: none;
            font: inherit;
            font-size: $font-size-13;
            color: $color-text-muted;
            cursor: pointer;

            &:hover {
                color: $color-text;
            }
        }

        .language-switch__option--active {
            color: $color-text;
            font-weight: 600;
        }
    `,
    changeDetection: ChangeDetectionStrategy.OnPush,
})
export class LanguageSwitchComponent {
    protected readonly language = inject(LanguageService);
    protected readonly auth = inject(AuthenticationService);
    protected readonly locales = Locales;
    protected readonly names = LocaleNames;

    protected choose(locale: Locale): void {
        void this.language.use(locale);
    }
}
