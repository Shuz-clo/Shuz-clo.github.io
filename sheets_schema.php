<?php
// ============================================================
// sheets_schema.php
// Draft sheets: header, layout JSON, and per-cell values.
// Updated to exactly match PO III_FO.xlsx
// ============================================================

function ensure_sheets_schema(mysqli $conn): void {
    static $ready = false;
    if ($ready) return;

    $conn->query("CREATE TABLE IF NOT EXISTS sheets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        title VARCHAR(150) NOT NULL DEFAULT 'Untitled sheet',
        candidate_name VARCHAR(255) DEFAULT '',
        position_title VARCHAR(255) DEFAULT '',
        office_name VARCHAR(255) DEFAULT '',
        interview_date VARCHAR(255) DEFAULT '',
        status ENUM('draft','submitted') NOT NULL DEFAULT 'draft',
        layout_json LONGTEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_user (user_id),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS sheet_cells (
        sheet_id INT NOT NULL,
        cell_key VARCHAR(80) NOT NULL,
        `value` TEXT NULL,
        PRIMARY KEY (sheet_id, cell_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS evidence_options (
        id INT AUTO_INCREMENT PRIMARY KEY,
        label VARCHAR(60) NOT NULL UNIQUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("INSERT IGNORE INTO evidence_options (label) VALUES ('Advanced'), ('Basic')");

    $ready = true;
}

// Exact layout extracted from PO III_FO.xlsx
function default_sheets_layout(): array {
    return [
        'sections' => [
            'core' => [
                'label' => 'Core Competencies',
                'rows'  => [
                    ['id' => 'cc1', 'title' => 'CC1 - DISCIPLINE', 'desc' => 'Upholds the values of DENR in every action and decision'],
                    ['id' => 'cc2', 'title' => 'CC2 - EXCELLENCE', 'desc' => 'Delivers and adds value to customers’ expectations and requirements'],
                    ['id' => 'cc3', 'title' => 'CC3 - NOBILITY', 'desc' => 'Demonstrates virtue, goodness, honor, justness and decency even in difficult situations'],
                    ['id' => 'cc4', 'title' => 'CC4 - RESPONSIBILITY', 'desc' => 'Practices the principle of transparency and accountability in the workplace'],
                    ['id' => 'cc5', 'title' => "CC5 - CARING FOR THE ENVIRONMENT\nAND NATURAL RESOURCES", 'desc' => 'Applies knowledge and understanding of the environment to further the aims of sustainability'],
                ],
            ],
            'org' => [
                'label' => 'Organizational Competencies',
                'rows'  => [
                    ['id' => 'oc1', 'title' => 'OC1 - WRITING EFFECTIVELY', 'desc' => 'Edits existing or customizes available communication materials to produce an appropriate written work'],
                    ['id' => 'oc2', 'title' => 'OC2 - SPEAKING EFFECTIVELY', 'desc' => 'Effectively delivers messages that require some planning for the Method used and the possible reception to the message (audience may be a controlled group, i.e., team/s, divisions) Focus of communication is to relay information and to build motivation'],
                    ['id' => 'oc3', 'title' => 'OC3 - TECHNOLOGY LITERACY AND MANAGING INFORMATION', 'desc' => 'Demonstrates the use of available equipment and software/s and application/s that can be utilized in the performance of the assigned tasks. Works with data to generate relevant information'],
                    ['id' => 'oc4', 'title' => 'OC4 - PROJECT MANAGEMENT', 'desc' => 'Conducts project management activities'],
                    ['id' => 'oc5', 'title' => 'OC5 - COMPLETE STAFF WORK (CSW)', 'desc' => 'Demonstrates advanced knowledge in CSW by applying appropriate tools and techniques'],
                ],
            ],
            'lead' => [
                'label' => 'Leadership Competencies',
                'rows'  => [
                    ['id' => 'lc1', 'title' => 'LC1 – STRATEGIC LEADERSHIP (THINKING STRATEGICALLY AND CREATIVELY)', 'desc' => 'Develops office/ service’s strategies and plans based on the DENR’s mission/vision'],
                    ['id' => 'lc2', 'title' => 'LC2 – LEADING CHANGE', 'desc' => 'Effectively delivers messages that require careful planning for the method used and the possible impact of the message (audience may be a large group, i.e., office, organization) Focus of communication is to relay information and to build motivation'],
                    ['id' => 'lc3', 'title' => 'LC3 – PEOPLE DEVELOPMENT (CREATING AND NURTURING A HIGH PERFORMING ORGANIZATION)', 'desc' => 'Analyzes appropriateness of office software and equipment in the performance of assigned tasks. Develops information assets to achieve organizational goals'],
                    ['id' => 'lc4', 'title' => 'LC4 – PEOPLE PERFORMANCE MANAGEMENT (MANAGING PERFORMANCE AND COACHING FOR RESULTS)', 'desc' => 'Leads in project management activities'],
                    ['id' => 'lc5', 'title' => 'LC5 – PARTNERSHSIP AND NETWORKING (BUILDING COLLABORATIVE AND INCLUSIVE WORKING RELATIONSHIPS)', 'desc' => 'Leads the practice of CSW in his/her office/unit'],
                ],
            ],
            'func' => [
                'label' => 'Functional Competencies',
                'rows'  => [
                    ['id' => 'pco1', 'title' => 'PCO1 – CONCEPT AND APPLICATION OF INTEGRATED ECOSYSTEMS MANAGEMENT (IEM)', 'desc' => 'Applies IEM and other related approaches to NRM'],
                    ['id' => 'pco2', 'title' => 'PCO2 – IDENTIFICATION OF INTERVENTIONS AND INTEGRATING STRATEGIES ACROSS (FORESTRY, COASTAL, AGRICULTURE, URBAN, AIR PACE) AND ZONING FOR STRATEGIC MANAGEMENT', 'desc' => 'Identifies interventions and integrating strategies across sectors'],
                    ['id' => 'pco3', 'title' => 'PCO3 – CHARACTERIZATION OF ECOSYSTEMS AND USE OF PLANNING TOOLS AND PROCEDURES', 'desc' => 'Undertakes characterization of ecosystems and use of planning tools and procedures'],
                    ['id' => 'pco4', 'title' => 'PCO4 – RESOURCE MANAGEMENT AND RESTORATION/ REHABILITATION OF DEGRADED ECOSYSTEMS', 'desc' => 'Identifies and implements appropriate resource management and restoration strategies of various ecosystems'],
                    ['id' => 'pco5', 'title' => 'PCO5 – PREPARATION OF NATURAL RESOURCES MANAGEMENT (NRM)-RELATED PLANS (FLUP, CRMP, ISWMP, PAMP, IRBM, IWRM)', 'desc' => 'Facilitates/ provides technical assistance in the formulation and implementation of NRM- related plans (FLUP, CRMP, ISWMP, PAMP, IRBM, IWRM) and integration to LGU plans.'],
                    ['id' => 'pco6', 'title' => 'PCO6 – ENVIRONMENT AND NATURAL RESOURCE (ENR) ACCOUNTING', 'desc' => 'Undertakes ENR accounting activities'],
                    ['id' => 'pco7', 'title' => 'PCO7 – STRATEGIES AND SCHEMES FOR FINANCING ENVIRONMENTAL PROJECTS', 'desc' => 'Plans, initiates and operationalizes strategies and schemes for financing environmental projects'],
                    ['id' => 'pco8', 'title' => 'PCO8 – RESULTS-BASED MONITORING AND EVALUATION SYSTEM (RBME) AND ENVIRONMENTAL AUDIT FOR DIFFERENT ENRM SITES', 'desc' => 'Implements RBME activities and environmental audit for different ENRM sites.'],
                    ['id' => 'pco9', 'title' => 'PCO9 – ENVIRONMENTAL GOVERNANCE', 'desc' => 'Undertakes environmental governance activities that include transparency, accountability, participation, collaboration and functionality'],
                    ['id' => 'pco10', 'title' => 'PCO10 – CLIMATE CHANGE AND ENVIRONMENTAL MANAGEMENT', 'desc' => 'Applies mitigating measures and adaptive solutions to climate change'],
                    ['id' => 'pco11', 'title' => 'PCO11 – INFORMATION, EDUCATION AND COMMUNICATION, SOCIAL MARKETING AND EXTENSION SUPPORT', 'desc' => 'Undertakes social marketing and extension support activities to improve ENRM'],
                    ['id' => 'pco12', 'title' => 'PCO12 – SOCIAL NEGOTIATION', 'desc' => 'Conducts social negotiation activities'],
                    ['id' => 'pco13', 'title' => 'PCO13 – ENR LAW ENFORCEMENT', 'desc' => 'Implements ENR law enforcement activities'],
                    ['id' => 'pco14', 'title' => 'PCO14 – LAND DISPOSITION AND MANAGEMENT', 'desc' => 'Processes and evaluates public land applications.'],
                    ['id' => 'pco15', 'title' => 'PCO15 – FOREST, WATER AND WILDLIFE RESOURCES REGULATION', 'desc' => 'Processes and evaluates forest, water and wildlife resource utilization and regulation activities'],
                    ['id' => 'pco16', 'title' => 'PCO16 – TENURE AND RIGHTS ASSESSMENT', 'desc' => 'Evaluates effectiveness of tenure holders based on organizational, stewardship, protection and management capabilities'],
                    ['id' => 'pco17', 'title' => 'PCO17 – TENURIAL INSTRUMENTS AND PERMITS FOR IMPROVED RESOURCE MANAGEMENT', 'desc' => 'Assist in the preparation of tenurial instruments and permits for Prepares tenurial instruments and permits for improved resource management.'],
                ],
            ],
        ],
    ];
}

function load_evidence_options(mysqli $conn): array {
    $out = [];
    $res = $conn->query("SELECT label FROM evidence_options ORDER BY id ASC");
    if ($res) {
        while ($row = $res->fetch_assoc()) $out[] = $row['label'];
    }
    return $out;
}