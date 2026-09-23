
    // ==============================
    // MAKE ALL CELLS EDITABLE
    // ==============================

    document.querySelectorAll("td").forEach(function (cell) {

        cell.contentEditable = "true";
        cell.style.cursor = "text";

    });


    // ==============================
    // SAVE SCORESHEET
    // ==============================

    function saveScoresheet() {

        // Get the current HTML of the entire page
        const html =
            "<!DOCTYPE html>\n" +
            document.documentElement.outerHTML;

        // Create the file
        const blob = new Blob(
            [html],
            {
                type: "text/html;charset=utf-8"
            }
        );

        // Create temporary download link
        const link = document.createElement("a");

        link.href = URL.createObjectURL(blob);

        // File name
        link.download = "Interview_Scoring_Sheet.html";

        // Download
        document.body.appendChild(link);
        link.click();

        // Clean up
        document.body.removeChild(link);
        URL.revokeObjectURL(link.href);
    }
