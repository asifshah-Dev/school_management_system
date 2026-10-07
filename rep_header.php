<?php
// Default language (English only now)
$lang = 'en';

// Language strings for header
$header_translations = [
    'en' => [
        'institution_name' => 'Al-Farooqia Madrassa for Tajweed and Qiraat, New Colony, Matta, Swat, Pakistan',
        'contact_info' => 'Contact: 03469401982 | 03456321051 | 0946790442',
        'registration_info' => 'Registration: 5892 | Affiliation: 08958'
    ]
];
?>

<div class="rep_header">
    <div class="header-container">
        <img src="logo.jpg" alt="Logo" class="logo" />
        <div class="header-content">
            <div class="title"><?php echo htmlspecialchars($header_translations[$lang]['institution_name']); ?></div>
            <div class="contact-info">
                <span><?php echo htmlspecialchars($header_translations[$lang]['contact_info']); ?></span>
            </div>
            <div class="registration-info">
                <span><?php echo htmlspecialchars($header_translations[$lang]['registration_info']); ?></span>
            </div>
        </div>
    </div>
</div>

<style>
    .rep_header {
        width: 100%;
        margin-bottom: 20px;
        padding: 10px 0;
        border-bottom: 2px solid #000;
    }
    .header-container {
        display: flex;
        align-items: center;
        max-width: 100%;
    }
    .logo {
        height: 30mm; /* Decreased from 60mm */
        width: auto;
        margin-right: 15px;
    }
    .header-content {
        flex: 1;
        text-align: center;
    }
    .title {
        font-size: 18pt;
        font-weight: bold;
        color: #000;
        margin-bottom: 5px;
        word-wrap: break-word;
        overflow-wrap: break-word;
        line-height: 1.2;
    }
    .contact-info {
        font-size: 12pt;
        color: #333;
        margin-bottom: 5px;
        text-align: center;
    }
    .contact-info span {
        display: inline;
    }
    .registration-info {
        font-size: 12pt;
        color: #333;
        text-align: right;
    }
    .registration-info span {
        display: inline;
    }
    .rep_header, .title, .contact-info {
        direction: ltr;
        text-align: center;
    }
    @media print {
        .rep_header {
            position: running(header);
            margin-bottom: 10px;
        }
        .logo {
            height: 25mm;
        }
        .title {
            font-size: 16pt;
        }
        .contact-info, .registration-info {
            font-size: 10pt;
        }
    }
</style>
