import { HttpContextToken } from '@angular/common/http';

export const SILENT_HTTP_ERROR = new HttpContextToken<boolean>(() => false);
export const LONG_RUNNING_REQUEST = new HttpContextToken<boolean>(() => false);
