# Security policy

## Reporting

Please report suspected vulnerabilities privately to the repository maintainer. Do not open a public issue containing credentials, personal data, exploitable payloads or production infrastructure details.

## Deployment baseline

- Keep `.env`, database dumps, logs and AWS credentials outside version control.
- Prefer EC2/ECS IAM roles or other short-lived AWS credentials.
- Keep S3 buckets private and grant only the required object actions.
- Serve MiChat over HTTPS and use secure PHP session-cookie settings.
- Preserve CSRF validation and ownership checks on every mutating endpoint.
- Run MySQL with a dedicated least-privilege account and maintain tested backups.
- Supervise the Task worker and use a unique worker identity per process.
- Review pending write proposals in Task Center before approval.

Tool approvals are exact-fingerprint and at-most-once. They reduce accidental execution risk but do not replace operating-system, database, S3 and IAM isolation.
