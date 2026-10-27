<?php
// ============================================================
// sheets_schema.php
// Draft sheets: header, layout JSON, and per-cell values.
// Updated to exactly match PO III_FO.xlsx
// ============================================================

function ensure_sheets_schema(mysqli $conn): void {
    if (!app_allow_ddl()) return;
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
                    ['id' => 'cc1', 'title' => 'CC1', 'desc' => 'Sponsors/initiates development of proactive programs promoting DENR values and ethics'],
                    ['id' => 'cc2', 'title' => 'CC2', 'desc' => 'Builds and shapes DENR service culture and strategic agenda and provides leadership in service delivery through the highest degree of responsibility and competence'],
                    ['id' => 'cc3', 'title' => 'CC3', 'desc' => 'Inspires others to act at the highest level of virtue, goodness, honor, justness and decency'],
                    ['id' => 'cc4', 'title' => 'CC4', 'desc' => 'Champions the principle of transparency and accountability in the agency'],
                    ['id' => 'cc5', 'title' => 'CC5', 'desc' => 'Sponsors development of proactive programs promoting the preservation and conservation of the environment'],
                ],
            ],
            'org' => [
                'label' => 'Organizational Competencies',
                'rows'  => [
                    ['id' => 'oc1', 'title' => 'OC1', 'desc' => 'Designs and/or sets standards for a written material used within the bureaucracy while demonstrating independence in producing written work'],
                    ['id' => 'oc2', 'title' => 'OC2', 'desc' => 'Facilitates and influences target audiences such as Heads of the Agency and external partners/clients. Focuses communication on relaying information, building motivation and calling for action'],
                    ['id' => 'oc3', 'title' => 'OC3', 'desc' => 'Maximizes the use of equipment, office software and applications in performing assigned tasks. Strategizes the method and use of information that adds value to the organization and public sector'],
                    ['id' => 'oc4', 'title' => 'OC4', 'desc' => 'Formulates plans, programs and policies on the improvement of project management activities'],
                    ['id' => 'oc5', 'title' => 'OC5', 'desc' => 'Institutionalizes and sustains the practice of CSW in the organization'],
                ],
            ],
            'lead' => [
                'label' => 'Leadership Competencies',
                'rows'  => [
                    ['id' => 'lc1', 'title' => 'LC1', 'desc' => "Ensures that others support the DENR's mission, goals, agenda, climate and policy"],
                    ['id' => 'lc2', 'title' => 'LC2', 'desc' => 'Ensures the effective implementation of any change initiatives/programs of the DENR by guiding the team in the transition process'],
                    ['id' => 'lc3', 'title' => 'LC3', 'desc' => 'Motivates, trains, coaches and mentors people towards achieving quality results'],
                    ['id' => 'lc4', 'title' => 'LC4', 'desc' => 'Monitors employee performance regularly to help employees improve their performance'],
                    ['id' => 'lc5', 'title' => 'LC5', 'desc' => 'Builds and develops local partnerships and networks and identifies opportunities to deliver or enhance work outcomes'],
                ],
            ],
            'func' => [
                'label' => 'Functional Competencies',
                'rows'  => [
                    ['id' => 'pco1', 'title' => 'PCO1-S', 'desc' => 'Formulates and recommends strategies, policies, guidelines, procedures and criteria on integrated ecosystem management'],
                    ['id' => 'pco2', 'title' => 'PCO2-S', 'desc' => 'Formulates and recommends strategies, policies, guidelines, procedures and criteria for identifying interventions and integrating strategies across sectors'],
                    ['id' => 'pco3', 'title' => 'PCO3-S', 'desc' => 'Formulates and recommends strategies, policies, guidelines, procedures and criteria for characterizing ecosystems and using planning tools and procedures'],
                    ['id' => 'pco4', 'title' => 'PCO4-S', 'desc' => 'Formulates and recommends strategies, policies, guidelines, procedures and criteria for identifying appropriate resource management and restoration strategies for various ecosystems'],
                    ['id' => 'pco5', 'title' => 'PCO5-S', 'desc' => 'Recommends strategies, policies, guidelines, procedures and criteria for preparing NRM-related plans (FLUP, CRMP, ISWMP, PAMP, IRBM, IWRM) and integrating them into LGU plans'],
                    ['id' => 'pco6', 'title' => 'PCO6-S', 'desc' => 'Recommends strategies, policies, guidelines, procedures and criteria for ENR accounting activities'],
                    ['id' => 'pco7', 'title' => 'PCO7-S', 'desc' => 'Recommends strategies, policies, guidelines, procedures and criteria for planning, initiating and operationalizing strategies and schemes for financing environmental projects'],
                    ['id' => 'pco8', 'title' => 'PCO8-S', 'desc' => 'Formulates and recommends strategies, policies, guidelines, procedures and criteria for implementing RBME and environmental audit activities for different ENRM sites'],
                    ['id' => 'pco9', 'title' => 'PCO9-S', 'desc' => 'Formulates and recommends strategies, policies, guidelines, procedures and criteria for environmental governance activities'],
                    ['id' => 'pco10', 'title' => 'PCO10-S', 'desc' => 'Recommends strategies, policies, guidelines, procedures and criteria for applying mitigating measures and adaptive solutions to climate change'],
                    ['id' => 'pco11', 'title' => 'PCO11-S', 'desc' => 'Formulates and recommends strategies, policies, guidelines, procedures and criteria for conducting social marketing and extension support activities to improve ENRM'],
                    ['id' => 'pco12', 'title' => 'PCO12-S', 'desc' => 'Formulates and recommends strategies, policies, guidelines, procedures and criteria for conducting social negotiation activities'],
                    ['id' => 'pco13', 'title' => 'PCO13-S', 'desc' => 'Introduces strategies on ENR operation and recommends policies on environmental protection and management (forest protection strategies, denying the source, cutting off routes and denying the market); conducts training of field staff in implementing ENR rules and regulations; collaborates and networks with institutions, law enforcement agencies, NGOs, media and other concerned stakeholders; establishes and maintains linkages; and acts as resource person on ENR laws and rules and regulations'],
                    ['id' => 'pco14', 'title' => 'PCO14-S', 'desc' => 'Recommends plans, programs and policies on land management activities'],
                    ['id' => 'pco15', 'title' => 'PCO15-S', 'desc' => 'Recommends plans, strategies, programs and policies on forest, water and wildlife resource utilization and regulation activities'],
                    ['id' => 'pco16', 'title' => 'PCO16-S', 'desc' => 'Formulates and recommends strategies, guidelines, procedures and criteria for evaluating the effectiveness of tenure holders based on organizational, stewardship, protection and management capabilities'],
                    ['id' => 'pco17', 'title' => 'PCO17-S', 'desc' => 'Formulates and recommends strategies, guidelines, procedures and criteria for issuing tenurial instruments and permits for improved resource management'],
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