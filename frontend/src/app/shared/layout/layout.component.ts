import {ChangeDetectionStrategy, Component, inject, OnInit, signal} from '@angular/core';
import {RouterLink, RouterLinkActive, RouterOutlet} from '@angular/router';
import {AuthenticationService} from '@app/services/authentication.service';
import {CurrentUserService} from '@app/services/current-user.service';

/**
 * Shell of the signed-in app: top bar with navigation and the user's menu, content below.
 * On a phone the navigation and the user's menu fold into a hamburger menu.
 */
@Component({
    selector: 'fd-layout',
    imports: [RouterOutlet, RouterLink, RouterLinkActive],
    templateUrl: './layout.component.html',
    styleUrl: './layout.component.scss',
    changeDetection: ChangeDetectionStrategy.OnPush,
    host: {'(document:keydown.escape)': 'menuOpen.set(false)'},
})
export class LayoutComponent implements OnInit {
    protected readonly currentUser = inject(CurrentUserService);
    protected readonly menuOpen = signal(false);
    private readonly auth = inject(AuthenticationService);

    public ngOnInit(): void {
        // Failure is already reported by the interceptors (toast, or sign-out on 401).
        this.currentUser.get().catch(() => undefined);
    }

    protected toggleMenu(): void {
        this.menuOpen.update((open) => !open);
    }

    protected logout(): void {
        this.auth.logout();
    }
}
