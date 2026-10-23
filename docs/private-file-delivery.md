# PDF viewer and download delivery

The Knowledge/RAG eye and download actions continue to use `ver_pdf.php?archivo=<S3 key>` and `descargar.php?archivo=<S3 key>`. Both now use `Config::getBucket()`, matching `S3Manager`, rather than the portable example constant `Config::BUCKET = yours3bucket`. The download no longer loads a nonexistent `michat/vendor` before the application bootstrap.

`includes/Files/FileDeliveryService.php` resolves the exact canonical key from live `FileS3` records before S3 reads. A basename or a client-supplied visible name does not authorize another object. Current support is for the authenticated owner's active files; protected files require the existing Drive unlock session. This does not add shared-file grants. Deleted/other-owner Drive records deny access even if referenced by an old message. Legacy generated chat media without a `FileS3` record remains accessible through its owner's nondeleted conversation, including thumbnails.

Responses are private and noncacheable, preserve the database filename with safe UTF-8 disposition, stream the body in bounded reads and support full GET, HEAD and single byte ranges. GET uses the inspected ETag as `IfMatch`. A stale `If-Range` requests the full current object. Unsupported ranges return 416. PDF inline responses require a `.pdf` key. No S3 writes, ACL changes or schema changes are involved. An unset/example bucket gives an explicit configuration error; unexpected AWS failures return a generic error without exposing AWS URLs or request details.

## Validation

- `php michat/tests/file_delivery_test.php`: 18 range and header checks.
- `DOCUMENT_TEST_DB_HOST=127.0.0.1 ... php michat/tests/file_delivery_mysql_test.php`: 34 checks through a loopback PHP HTTP server executing both real endpoints, actual temporary MariaDB/MySQL tables, and an S3 double. Tests verify binary responses, full/partial requests, configured bucket, ETag, auth isolation, protected/deleted files, generated media and safe errors. Use an isolated test database, never production. The existing regression CI supplies the isolated MariaDB service and environment.
- All PHP syntax, 107 PHP test scripts and 11 JavaScript test scripts passed locally. Environment-dependent suites explicitly skip when their integration environment is absent; document DB integrations were also run separately against isolated MariaDB 10.11.
- Live EC2/S3 verification remains necessary; tests do not establish that production IAM/KMS grants or a particular object are accessible.

## EC2 update and verification

From the deployment checkout, with a clean working tree, run `git pull --ff-only origin main` in `/var/www/michat`. Preserve the existing environment/private configuration. `Config::getBucket()` must return the real application bucket (the portable configuration reads `AWS_S3_BUCKET`). Never use `yours3bucket` or grant public/bucket-wide access to bypass an error.

While authenticated as the file owner, open the existing Knowledge/RAG eye action and download action for `Data/Docs/legal/Machotes/CONVENIO DE CONFIDENCIALIDAD.pdf`. Expect a full PDF response (200) or a valid range response (206), and a download with the visible filename and identical bytes. Verify unauthenticated and other-user requests are denied. If the runtime bucket is configured but S3 still rejects the read, inspect the server's object access using its existing AWS identity and the exact database key; this patch deliberately does not modify IAM, KMS or bucket policies.
