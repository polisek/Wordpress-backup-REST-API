const axios = require('axios');
const fs = require('fs');
const path = require('path');

const projects = [
    {
        name: 'example.com',
        url: 'https://example.com/wp-json/backup/v1/download',
        apiKey: 'your-own-code-same-as-admin',
        user: 'userName',
    },
];

function getTimestamp() {
    const now = new Date();
    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}-${String(now.getHours()).padStart(2, '0')}-${String(now.getMinutes()).padStart(2, '0')}`;
}

async function downloadFile(fileUrl, outputLocationPath) {
    const writer = fs.createWriteStream(outputLocationPath);
    const response = await axios({
        url: fileUrl,
        method: 'GET',
        responseType: 'stream',
    });

    response.data.pipe(writer);

    return new Promise((resolve, reject) => {
        writer.on('finish', resolve);
        writer.on('error', reject);
    });
}

async function backupProject(project) {
    try {
        console.log(`Fetching backup data for project: ${project.name}`);
        const response = await axios.get(project.url, { params: { key: project.apiKey } });

        const backupData = response.data;
        const timestamp = getTimestamp(); 
        const backupDir = path.join(__dirname, project.name, project.user, timestamp);

        if (!fs.existsSync(backupDir)) {
            fs.mkdirSync(backupDir, { recursive: true });
        }

        for (const [key, value] of Object.entries(backupData)) {
            if (value.success) {
                console.log(`Downloading ${key} for project: ${project.name}`);

                // Rozhodněte se podle přípony souboru
                const fileUrl = value.file;
                const fileName = fileUrl.endsWith('.sql')
                    ? 'database-backup.sql'
                    : `${key}-backup.zip`;

                const outputLocation = path.join(backupDir, fileName);
                await downloadFile(fileUrl, outputLocation);
                console.log(`Downloaded ${fileName} to ${outputLocation}`);
            } else {
                console.error(`Error in ${key} for project: ${project.name}:`, value.error, value.details || '');
            }
        }

        console.log(`Backup completed for project: ${project.name}`);
    } catch (error) {
        console.error(`Error during backup for project: ${project.name}`, error.message);
    }
}

async function backupAllProjects() {
    for (const project of projects) {
        await backupProject(project);
    }
}

backupAllProjects();

setInterval(() => {
    console.log('Backup started..');
    backupAllProjects();
}, 24* 60 * 1000); // 24hs

