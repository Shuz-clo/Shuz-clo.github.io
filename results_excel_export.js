(function (global) {
    'use strict';

    function cellStyle(fill, color = '000000', bold = false, align = 'center') {
        const argb = value => value.length === 6 ? 'FF' + value : value;
        const border = { style: 'thin', color: { rgb: 'FF000000' } };
        return {
            fill: { patternType: 'solid', fgColor: { rgb: argb(fill) } },
            font: { name: 'Arial', sz: 10, bold, color: { rgb: argb(color) } },
            alignment: { horizontal: align, vertical: 'center', wrapText: true },
            border: { top: border, bottom: border, left: border, right: border }
        };
    }

    function sheetName(label, used) {
        const base = String(label).replace(/[\\/?*:[\]]/g, ' ').trim().slice(0, 31) || 'Office';
        let name = base;
        let suffix = 2;
        while (used.has(name.toLowerCase())) {
            const ending = ' (' + suffix++ + ')';
            name = base.slice(0, 31 - ending.length) + ending;
        }
        used.add(name.toLowerCase());
        return name;
    }

    function buildWorksheet(options, office, candidates) {
        const { XLSX, headerLabels, raterColumns, blockKey, minSlots, todayDate } = options;
        const rows = [];
        const merges = [];
        const rowHeights = [];
        const sections = [];
        const positions = new Map();
        candidates.forEach(candidate => {
            const position = candidate.position || 'UNASSIGNED';
            if (!positions.has(position)) positions.set(position, []);
            positions.get(position).push(candidate);
        });
        if (!positions.size) positions.set('UNASSIGNED', []);

        positions.forEach((positionCandidates, position) => {
            const dates = new Map();
            positionCandidates.forEach(candidate => {
                const date = candidate.date || todayDate;
                if (!dates.has(date)) dates.set(date, []);
                dates.get(date).push(candidate);
            });
            if (!dates.size) dates.set(todayDate, []);

            dates.forEach((dateCandidates, date) => {
                const raters = raterColumns(dateCandidates, blockKey(office, position, date), office, position);
                const slots = Math.max(minSlots, raters.length + 1);
                const psychoStart = 2;
                const psychoAverage = psychoStart + slots;
                const potentialStart = psychoAverage + 1;
                const potentialAverage = potentialStart + slots;
                const idColumn = potentialAverage + 1;
                const officeColumn = idColumn + 1;
                const metaRow = rows.length;
                const groupRow = metaRow + 1;
                const headerRow = metaRow + 2;
                const meta = Array(officeColumn + 1).fill('');
                meta[0] = 'Date/Time: ' + date;
                meta[3] = 'POSITION TITLE: ' + position;
                rows.push(meta);
                const group = Array(officeColumn + 1).fill('');
                group[psychoStart] = headerLabels.psychoGroup || 'PSYCHO-SOCIAL';
                group[potentialStart] = headerLabels.potentialGroup || 'POTENTIAL';
                rows.push(group);
                const headings = Array(officeColumn + 1).fill('');
                headings[0] = headerLabels.hash || '#';
                headings[1] = headerLabels.name || 'Name';
                for (let index = 0; index < slots; index++) {
                    headings[psychoStart + index] = raters[index] || '';
                    headings[potentialStart + index] = raters[index] || '';
                }
                headings[psychoAverage] = headerLabels.psychoAve || 'AVE.';
                headings[potentialAverage] = headerLabels.potentialAve || 'AVE.';
                headings[idColumn] = '__ID';
                headings[officeColumn] = '__OFFICE';
                rows.push(headings);
                merges.push(
                    { s: { r: metaRow, c: 0 }, e: { r: metaRow, c: 2 } },
                    { s: { r: metaRow, c: 3 }, e: { r: metaRow, c: potentialAverage } },
                    { s: { r: groupRow, c: 0 }, e: { r: headerRow, c: 0 } },
                    { s: { r: groupRow, c: 1 }, e: { r: headerRow, c: 1 } },
                    { s: { r: groupRow, c: psychoStart }, e: { r: groupRow, c: psychoAverage } },
                    { s: { r: groupRow, c: potentialStart }, e: { r: groupRow, c: potentialAverage } }
                );

                const minimumRows = Math.max(5, dateCandidates.length);
                const dataStartRow = rows.length;
                for (let rowIndex = 0; rowIndex < minimumRows; rowIndex++) {
                    const candidate = dateCandidates[rowIndex];
                    const excelRow = rows.length + 1;
                    const data = Array(officeColumn + 1).fill('');
                    if (candidate) {
                        data[0] = rowIndex + 1;
                        data[1] = candidate.name || '';
                        data[idColumn] = candidate.id || '';
                        data[officeColumn] = candidate.office || office;
                        for (let index = 0; index < slots; index++) {
                            const scores = (candidate.scores || {})[raters[index]] || {};
                            data[psychoStart + index] = scores.p ?? '';
                            data[potentialStart + index] = scores.t ?? '';
                        }
                    }
                    const encode = XLSX.utils.encode_col;
                    const psychoRange = encode(psychoStart) + excelRow + ':' + encode(psychoAverage - 1) + excelRow;
                    const potentialRange = encode(potentialStart) + excelRow + ':' + encode(potentialAverage - 1) + excelRow;
                    data[psychoAverage] = { f: 'IFERROR(AVERAGE(' + psychoRange + '),"")' };
                    data[potentialAverage] = { f: 'IFERROR(AVERAGE(' + potentialRange + '),"")' };
                    rows.push(data);
                }
                sections.push({ metaRow, groupRow, headerRow, dataStartRow, dataEndRow: rows.length - 1, psychoStart, psychoAverage, potentialStart, potentialAverage });
                rows.push(Array(officeColumn + 1).fill(''));
                rowHeights.push({ hpt: 23 }, { hpt: 23 }, { hpt: 23 });
                for (let index = 0; index < minimumRows; index++) rowHeights.push({ hpt: 20 });
                rowHeights.push({ hpt: 10 });
            });
        });

        const worksheet = XLSX.utils.aoa_to_sheet(rows);
        worksheet['!merges'] = merges;
        worksheet['!rows'] = rowHeights;
        worksheet['!pageSetup'] = { orientation: 'landscape', fitToWidth: 1, fitToHeight: 0 };
        worksheet['!margins'] = { left: 0.25, right: 0.25, top: 0.4, bottom: 0.4, header: 0.2, footer: 0.2 };
        const styleCell = (row, column, style) => {
            const address = XLSX.utils.encode_cell({ r: row, c: column });
            if (!worksheet[address]) worksheet[address] = { t: 's', v: '' };
            worksheet[address].s = style;
        };
        sections.forEach(section => {
            for (let column = 0; column <= section.potentialAverage; column++) {
                const isPositionCell = column >= 3;
                styleCell(section.metaRow, column, cellStyle(isPositionCell ? 'FF99CC' : 'FFFFFF', isPositionCell ? 'FF0000' : '000000', true, isPositionCell ? 'center' : 'left'));
                styleCell(section.groupRow, column, cellStyle('FFFFFF', '000000', true));
                styleCell(section.headerRow, column, cellStyle('FFFFFF', '000000', true));
                for (let row = section.dataStartRow; row <= section.dataEndRow; row++) {
                    styleCell(row, column, cellStyle('FFFFFF', '000000', false, column === 1 ? 'left' : 'center'));
                }
            }
            for (let column = section.psychoStart; column <= section.psychoAverage; column++) styleCell(section.groupRow, column, cellStyle('92D050', '000000', true));
            for (let column = section.potentialStart; column <= section.potentialAverage; column++) styleCell(section.groupRow, column, cellStyle('FFFF00', '000000', true));
            styleCell(section.headerRow, section.psychoAverage, cellStyle('A9D18E', '000000', true));
            styleCell(section.headerRow, section.potentialAverage, cellStyle('FFFF00', '000000', true));
            for (let row = section.dataStartRow; row <= section.dataEndRow; row++) {
                styleCell(row, section.psychoAverage, cellStyle('A9D18E', '000000', true));
                styleCell(row, section.potentialAverage, cellStyle('FFFF00', '000000', true));
                worksheet[XLSX.utils.encode_cell({ r: row, c: section.psychoAverage })].z = '0.00';
                worksheet[XLSX.utils.encode_cell({ r: row, c: section.potentialAverage })].z = '0.00';
                for (let column = section.psychoStart; column < section.psychoAverage; column++) {
                    const cell = worksheet[XLSX.utils.encode_cell({ r: row, c: column })];
                    if (cell && typeof cell.v === 'number') cell.z = '0.00';
                }
                for (let column = section.potentialStart; column < section.potentialAverage; column++) {
                    const cell = worksheet[XLSX.utils.encode_cell({ r: row, c: column })];
                    if (cell && typeof cell.v === 'number') cell.z = '0.00';
                }
            }
        });
        const lastColumn = Math.max(0, ...rows.map(row => row.length - 1));
        worksheet['!cols'] = [{ wch: 4 }, { wch: 32 }];
        for (let column = 2; column <= lastColumn - 2; column++) {
            const averageColumn = sections.some(section => column === section.psychoAverage || column === section.potentialAverage);
            worksheet['!cols'].push({ wch: averageColumn ? 7.5 : 8 });
        }
        worksheet['!cols'].push({ wch: 0, hidden: true }, { wch: 0, hidden: true });
        return worksheet;
    }

    function exportExcel(options) {
        const { XLSX, groupedData, syncFromDom } = options;
        if (!XLSX) return global.alert('Excel export is unavailable because the workbook library did not load.');
        try {
            syncFromDom();
            const workbook = XLSX.utils.book_new();
            const usedNames = new Set();
            Object.entries(groupedData).forEach(([office, candidates]) => {
                XLSX.utils.book_append_sheet(workbook, buildWorksheet(options, office, candidates || []), sheetName(office, usedNames));
            });
            XLSX.writeFile(workbook, 'interview-rating-results.xlsx', { bookType: 'xlsx', compression: true });
        } catch (error) {
            global.alert('Excel export failed: ' + error.message);
        }
    }

    global.ResultsExcelExport = { exportExcel };
})(window);
