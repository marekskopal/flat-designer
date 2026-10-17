import {ChangeDetectionStrategy, Component, inject, OnInit} from '@angular/core';
import {RouterLink, RouterLinkActive, RouterOutlet} from '@angular/router';
import {AuthenticationService} from '@app/services/authentication.service';
import {CurrentUserService} from '@app/services/current-user.service';

/** Shell of the signed-in app: top bar with navigation and the user's menu, content below. */
@Component({
    selector: 'fd-layout',
    imports: [RouterOutlet, RouterLink, RouterLinkActive],
    templateUrl: './layout.component.html',
    styleUrl: './layout.component.scss',
    changeDetection: ChangeDetectionStrategy.OnPush,
})
export class LayoutComponent implements OnInit {
    protected readonly currentUser = inject(CurrentUserService);
    private readonly auth = inject(AuthenticationService);

    public ngOnInit(): void {
        // Failure is already reported by the interceptors (toast, or sign-out on 401).
        this.currentUser.get().catch(() => undefined);
    }

    protected logout(): void {
        this.auth.logout();
    }
}
