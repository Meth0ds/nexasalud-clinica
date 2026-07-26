import { Injectable, inject } from '@angular/core';
import { Observable, map, shareReplay } from 'rxjs';

import { ReferenceData } from '../models/app.models';
import { ApiClientService } from './api-client';

@Injectable({ providedIn: 'root' })
export class ReferenceDataService {
  private readonly api = inject(ApiClientService);
  private readonly dataRequest = this.api
    .get<{ readonly data: ReferenceData }>('/api/v1/reference-data')
    .pipe(
      map((response) => response.data),
      shareReplay({ bufferSize: 1, refCount: true }),
    );

  load(): Observable<ReferenceData> {
    return this.dataRequest;
  }
}
