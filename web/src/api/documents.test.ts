import { describe, expect, it, vi } from 'vitest';
import { api } from './client';
import { downloadDocumentByUrl } from './documents';

describe('downloadDocumentByUrl', () => {
  it('does not duplicate the API version from a document-library URL', async () => {
    const get = vi.spyOn(api, 'get').mockResolvedValue({ data: new Blob(['test']) } as never);

    await downloadDocumentByUrl('/api/v1/attachments/123/download');

    expect(get).toHaveBeenCalledWith('/attachments/123/download', { responseType: 'blob' });
  });
});
