# Interpret Results

Back to the [documentation index](../index.md).

The last part of the journey shows a preparation and coverage summary for ESG/ESRS work. It should be read as work status for the sustainability report, not as a developed ESRS report.

## Preparation Summary

The summary may include organisation identification, reporting year, activity code, material matters confirmed after double materiality, statuses, counters and limitations. It helps understand which pieces are recorded and which downloads can be attempted.

## Downloads

- **Decision sheet**: selected material matters and saved reasons.
- **Indicators/data list CSV**: ESRS inventory items selected by the application.
- **Responses CSV**: structured responses and references entered.
- **Summary JSON**: preparation status, coverage and technical traceability.
- **HTML package**: printable preparation, topic and coverage summary.
- **XBRL report, DOCX report and evidence bundle**: approved-version outputs containing the answers completed in step 5 (with their type and unit). The XBRL report is an Inline XBRL document with the same design as the other outputs: a cover with company, period and LEI, chapters and disclosure requirements with their ESRS code, and one card per datapoint with code, question and answer. Figures carry `ix:nonFraction` tags (the `pure` unit travels only in the tag and is not displayed) and narratives whose concept accepts text carry `ix:nonNumeric`. It can be opened in the browser or downloaded; evidence references are not printed.
- **XBRL report requirements**: a current approved version, compatible data, LEI and period dates, the EFRAG taxonomy (provisioned automatically) and Arelle validation, which takes 30 to 60 seconds. Figures, narratives, booleans, dates and years whose ESRS concept maps one-to-one are tagged; enumerations, datapoints that need dimensions or a future period, and unmapped datapoints are shown untagged.

## Answers and report version

Every answer completed in step 5 is report content: its value, data type (text, number, integer, amount, yes/no or date), unit and decimals are recorded once, there. Nothing is re-entered or reviewed in step 6: a report version is prepared, which freezes those answers, and approved with a declaration. If the characterisation, an answer or the reporting profile changes, the version is no longer current and another must be prepared and approved.

## How To Read Ready

A ready report download means a current approved version allows it to be generated. It does not mean the content is complete, accurate, assured, legally sufficient or accepted by a regulator.
