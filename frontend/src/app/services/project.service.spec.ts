import {provideHttpClient} from '@angular/common/http';
import {HttpTestingController, provideHttpClientTesting} from '@angular/common/http/testing';
import {provideZonelessChangeDetection} from '@angular/core';
import {TestBed} from '@angular/core/testing';
import {ProjectImport} from '@app/models/project';
import {environment} from '@environments/environment';
import {afterEach, beforeEach, describe, expect, it} from 'vitest';

import {ProjectService} from './project.service';

describe('ProjectService project files', () => {
    let service: ProjectService;
    let http: HttpTestingController;

    beforeEach(() => {
        TestBed.configureTestingModule({providers: [provideZonelessChangeDetection(), provideHttpClient(), provideHttpClientTesting()]});
        service = TestBed.inject(ProjectService);
        http = TestBed.inject(HttpTestingController);
    });

    afterEach(() => http.verify());

    it('downloads a project with its files, or the JSON alone', async () => {
        const zip = service.exportProject(7, true);
        const zipRequest = http.expectOne(`${environment.apiUrl}/projects/7/export`);
        expect(zipRequest.request.responseType).toBe('blob');
        zipRequest.flush(new Blob(['PK']));
        expect(await (await zip).text()).toBe('PK');

        const json = service.exportProject(7, false);
        http.expectOne(`${environment.apiUrl}/projects/7/export?files=0`).flush(new Blob(['{}']));
        expect(await (await json).text()).toBe('{}');
    });

    it('imports a project file as a multipart upload', async () => {
        const file = new File(['PK'], 'flat.izbik.zip', {type: 'application/zip'});
        const answer = {project: {id: 12, name: 'Flat'}, floorId: 30, warnings: ['File "x" was left out.']} as unknown as ProjectImport;

        const imported = service.importProject(file);
        const request = http.expectOne(`${environment.apiUrl}/projects/import`);
        expect(request.request.method).toBe('POST');
        const body = request.request.body as FormData;
        expect((body.get('file') as File).name).toBe('flat.izbik.zip');
        request.flush(answer);

        expect(await imported).toEqual(answer);
    });
});
