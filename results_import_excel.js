(function (global) {
    'use strict';

    function parseWorksheet(values, sheetName, invalidScores, groupedData, todayDate, defaultOffice) {
        if (!values.length) return [];
        const sheetOffice = /^(?:sheet|worksheet)\s*\d*$/i.test(sheetName) ? defaultOffice : sheetName || defaultOffice;
        const normalize = value => String(value ?? '').trim().toLowerCase().replace(/[^a-z0-9]/g, '');
        const firstHeaders = values[0].map(value => String(value).trim());
        const firstNormalized = firstHeaders.map(normalize);
        const officeIndex = firstNormalized.indexOf('office');
        const candidateIndex = firstNormalized.findIndex(label => label.includes('name'));
        const knownIds = new Set(Object.values(groupedData).flat().map(candidate => Number(candidate.id)).filter(id => id > 0));
        const parseScores = (cells, columns, rowNumber) => {
            const scores = {};
            columns.forEach(column => {
                const raw = String(cells[column.index] ?? '').trim();
                if (!raw) return;
                const score = Number(raw);
                if (!Number.isFinite(score) || score < 0 || score > 10) {
                    invalidScores.push(rowNumber);
                    return;
                }
                if (!scores[column.rater]) scores[column.rater] = { p: null, t: null };
                scores[column.rater][column.kind] = score;
            });
            return scores;
        };

        if (candidateIndex >= 0) {
            const indexes = {
                id: firstNormalized.indexOf('id'),
                office: officeIndex,
                position: firstNormalized.indexOf('position'),
                date: firstNormalized.indexOf('interviewdate'),
                name: candidateIndex
            };
            const scoreColumns = [];
            firstHeaders.forEach((label, index) => {
                const prefixMatch = label.match(/^(psycho(?:-social)?|potential)\s*[-:]\s*(.+)$/i);
                const suffixMatch = label.match(/^(.+?)\s*[-:]\s*(psycho(?:-social)?|potential)$/i);
                const match = prefixMatch || suffixMatch;
                if (match) {
                    const kindLabel = prefixMatch ? match[1] : match[2];
                    const rater = prefixMatch ? match[2] : match[1];
                    scoreColumns.push({ index, kind: kindLabel.toLowerCase().startsWith('psycho') ? 'p' : 't', rater: rater.trim() });
                }
            });
            if (!scoreColumns.length) {
                firstHeaders.forEach((label, index) => {
                    if (index === candidateIndex || index === officeIndex || index === indexes.id
                        || index === indexes.position || index === indexes.date || normalize(label).includes('ave')) return;
                    if (label) scoreColumns.push({ index, kind: 'p', rater: label });
                });
            }
            return values.slice(1).flatMap((cells, offset) => {
                const name = String(cells[indexes.name] ?? '').trim();
                if (!name) return [];
                const office = String((indexes.office >= 0 ? cells[indexes.office] : '') ?? '').trim().toUpperCase() || String(defaultOffice || sheetOffice).toUpperCase();
                const sourceId = indexes.id >= 0 ? Number(cells[indexes.id]) : 0;
                return [{
                    id: Number.isInteger(sourceId) && knownIds.has(sourceId) ? sourceId : 0,
                    name,
                    office,
                    position: indexes.position >= 0 ? String(cells[indexes.position] ?? '').trim() || 'UNASSIGNED' : 'UNASSIGNED',
                    date: indexes.date >= 0 ? String(cells[indexes.date] ?? '').trim() || todayDate : todayDate,
                    scores: parseScores(cells, scoreColumns, offset + 2)
                }];
            });
        }

        const nameHeaderIndex = values.findIndex(row => row.some(value => normalize(value).includes('name')));
        if (nameHeaderIndex < 0) throw new Error('Sheet "' + sheetName + '" needs a Candidate Name column.');
        const headers = values[nameHeaderIndex].map(value => String(value).trim());
        const normalizedHeaders = headers.map(normalize);
        const nameIndex = normalizedHeaders.findIndex(label => label.includes('name'));
        const idIndex = normalizedHeaders.findIndex(label => label === 'id');
        const officeIdColumn = normalizedHeaders.findIndex(label => label === 'office');
        const averageIndexes = normalizedHeaders.map((label, index) => label.includes('ave') ? index : -1).filter(index => index >= 0);
        if (nameIndex < 0) throw new Error('Sheet "' + sheetName + '" needs a Name column.');
        const groupHeader = values.slice(0, nameHeaderIndex).reverse().find(row =>
            row.some(value => normalize(value).includes('psycho')) && row.some(value => normalize(value).includes('potential'))
        ) || [];
        const potentialGroupIndex = groupHeader.findIndex(value => normalize(value).includes('potential'));
        const psychoAverage = averageIndexes[0] ?? potentialGroupIndex;
        const potentialAverage = averageIndexes[1] ?? (officeIdColumn >= 0 ? officeIdColumn : idIndex >= 0 ? idIndex : headers.length);
        const scoreColumnsFor = row => {
            const columns = [];
            const psychoEnd = psychoAverage > nameIndex ? psychoAverage : potentialGroupIndex > nameIndex ? potentialGroupIndex : potentialAverage;
            const potentialStart = averageIndexes.length >= 2 ? psychoAverage + 1 : potentialGroupIndex > nameIndex ? potentialGroupIndex : psychoEnd;
            for (let index = nameIndex + 1; index < psychoEnd; index++) {
                const rater = String(row[index] ?? '').trim();
                if (rater && !['id', 'office'].includes(normalize(rater)) && !normalize(rater).includes('ave')) columns.push({ index, kind: 'p', rater });
            }
            for (let index = potentialStart; index < potentialAverage; index++) {
                const rater = String(row[index] ?? '').trim();
                if (rater && !['id', 'office'].includes(normalize(rater)) && !normalize(rater).includes('ave')) columns.push({ index, kind: 't', rater });
            }
            if (!columns.length) {
                for (let index = nameIndex + 1; index < potentialAverage; index++) {
                    const rater = String(row[index] ?? '').trim();
                    if (rater && !normalize(rater).includes('ave')) columns.push({ index, kind: 'p', rater });
                }
            }
            return columns;
        };

        let currentScoreColumns = scoreColumnsFor(headers);
        let currentDate = todayDate;
        let currentPosition = 'UNASSIGNED';
        const parsed = [];
        values.forEach((cells, index) => {
            const firstCell = String(cells[0] ?? '').trim();
            if (/^date\s*\/\s*time\s*:/i.test(firstCell)) currentDate = firstCell.replace(/^date\s*\/\s*time\s*:\s*/i, '').trim();
            const positionCell = cells.find(value => /^position\s+title\s*:/i.test(String(value ?? '').trim()));
            if (positionCell) currentPosition = String(positionCell).replace(/^position\s+title\s*:\s*/i, '').trim() || 'UNASSIGNED';
            if (index <= nameHeaderIndex) return;
            if (normalize(cells[nameIndex]) === normalize(headers[nameIndex])) {
                currentScoreColumns = scoreColumnsFor(cells);
                return;
            }

            const name = String(cells[nameIndex] ?? '').trim();
            if (!name) return;
            const sourceId = idIndex >= 0 ? Number(cells[idIndex]) : 0;
            parsed.push({
                id: Number.isInteger(sourceId) && knownIds.has(sourceId) ? sourceId : 0,
                name,
                office: String((officeIdColumn >= 0 ? cells[officeIdColumn] : '') || sheetOffice).toUpperCase(),
                position: currentPosition,
                date: currentDate,
                scores: parseScores(cells, currentScoreColumns, index + 1)
            });
        });
        return parsed;
    }

    async function readWorkbook(file, groupedData, todayDate, defaultOffice) {
        if (!global.XLSX) throw new Error('Excel import is unavailable because the workbook library did not load.');
        const workbook = global.XLSX.read(await file.arrayBuffer(), { type: 'array', cellDates: false });
        const invalidScores = [];
        const candidates = workbook.SheetNames.flatMap(sheetName => {
            const values = global.XLSX.utils.sheet_to_json(workbook.Sheets[sheetName], { header: 1, defval: '', raw: false });
            return parseWorksheet(values, sheetName, invalidScores, groupedData, todayDate, defaultOffice);
        });
        const knownIds = new Set(Object.values(groupedData).flat().map(candidate => Number(candidate.id)).filter(id => id > 0));
        candidates.forEach(candidate => {
            if (!knownIds.has(candidate.id)) candidate.id = 0;
        });
        if (!candidates.length) throw new Error('No candidate rows were found in the workbook.');
        return { candidates, invalidScores };
    }

    global.ResultsExcelImport = { readWorkbook };
})(window);
