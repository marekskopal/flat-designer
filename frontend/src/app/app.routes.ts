import {Routes} from '@angular/router';
import {adminGuard, authGuard, guestGuard} from '@app/core/guards/auth.guards';
import {LayoutComponent} from '@app/shared/layout/layout.component';

export const appRoutes: Routes = [
    {
        path: 'login',
        canActivate: [guestGuard],
        title: 'Sign in · Flat Designer',
        loadComponent: async () => (await import('@app/authentication/login.component')).LoginComponent,
    },
    {
        path: 'sign-up',
        canActivate: [guestGuard],
        title: 'Sign up · Flat Designer',
        loadComponent: async () => (await import('@app/authentication/sign-up.component')).SignUpComponent,
    },
    {
        // OAuth consent for MCP clients; signs in inline, so no guard.
        path: 'oauth/authorize',
        title: 'Connect · Flat Designer',
        loadComponent: async () => (await import('@app/oauth/oauth-authorize.component')).OAuthAuthorizeComponent,
    },
    {
        // A share link: a guest view of one floor, also for signed-in users (no guard).
        path: 's/:token',
        title: 'Shared view · Flat Designer',
        loadComponent: async () => (await import('@app/shared-viewer/shared-viewer-page.component')).SharedViewerPageComponent,
    },
    {
        path: '',
        component: LayoutComponent,
        canActivate: [authGuard],
        children: [
            {path: '', pathMatch: 'full', redirectTo: 'projects'},
            {
                path: 'projects',
                title: 'Projects · Flat Designer',
                loadComponent: async () => (await import('@app/projects/projects.component')).ProjectsComponent,
            },
            {
                path: 'projects/:projectId',
                title: 'Project · Flat Designer',
                loadComponent: async () => (await import('@app/projects/project-detail.component')).ProjectDetailComponent,
            },
            {
                path: 'projects/:projectId/floors/:floorId',
                title: 'Flat Designer',
                loadComponent: async () => (await import('@app/viewer-page/viewer-page.component')).ViewerPageComponent,
            },
            {
                path: 'account',
                title: 'Account · Flat Designer',
                loadComponent: async () => (await import('@app/account/account.component')).AccountComponent,
            },
            {
                path: 'admin/users',
                canActivate: [adminGuard],
                title: 'Users · Flat Designer',
                loadComponent: async () => (await import('@app/admin/admin-users.component')).AdminUsersComponent,
            },
        ],
    },
    {path: '**', redirectTo: ''},
];
