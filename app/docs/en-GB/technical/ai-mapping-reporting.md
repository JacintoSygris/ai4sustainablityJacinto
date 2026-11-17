# AI, Mapping And Reporting

Back to the [technical manual](index.md).

## Included FastAPI Service

The matter to Disclosure Requirement mapping uses the approved map shipped in the repository by default (`data/ar16_to_esrs_dr_mapping_esrs2023_v1.json`, `source.status=approved`). `ESRS_MATTER_DR_MAPPING_PATH` is only needed to replace it with another absolute path or, with the value `off`, to disable topical datapoints:

```dotenv
# optional
ESRS_MATTER_DR_MAPPING_PATH=/var/www/html/data/ar16_to_esrs_dr_mapping_esrs2023_v1.json
```

```sh
# Directory: /srv/ia4sustainability
docker compose exec web php artisan esrs:validate-matter-dr-mapping /var/www/html/data/ar16_to_esrs_dr_mapping_esrs2023_v1.json
```

Expected result: the command completes successfully and lists matter and requirement coverage.

Check: if the path is missing, JSON is invalid or status is not `approved`, Laravel treats the mapping as unavailable.

## ESRS Information, Exports And Technical Candidate

ESRS information outputs and the package/technical candidate require real state and data prerequisites. An available download or ready state is not regulatory filing, assurance, content validation or third-party acceptance.

Typical technical prerequisites:

- authenticated user and, if required, verified email;
- characterisation created;
- materiality decision recorded;
- sufficient datapoint/fact responses;
- validated ESRS/DR mapping if the output needs it;
- reporting assets present and intact;
- executable Arelle for candidate iXBRL validation.

Validate assets:

```sh
# Directory: /srv/ia4sustainability
docker compose exec web php artisan report:validate-assets
```

Expected result: `report:validate-assets` reports `OK`.

## Arelle And External Taxonomy

The XHTML/iXBRL candidate does not use a taxonomy included in the image or repository. The installation provides the authorised EFRAG ZIP separately, a private manifest with its SHA-256, and an absolute Arelle path. Laravel runs Arelle without network access; if the manifest, package, checksum or executable is missing, it blocks the candidate rather than declaring a valid download.

Follow the [external EFRAG taxonomy installation](external-efrag-taxonomy.md). `php artisan report:validate-assets` checks versioned assets and that taxonomy bytes have not been reintroduced into the repository; external ZIP validation occurs when the authenticated candidate is requested.

## Strict Interpretation Of Candidate XHTML/iXBRL

The XHTML/iXBRL candidate is a technical output for review. `arelle_validation.status=passed` only means the configured technical validation did not fail. It does not prove the report is complete, correct, audited, filed or accepted. Any external use requires professional review and the operator's procedure.
