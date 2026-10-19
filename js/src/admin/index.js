import app from 'flarum/admin/app';
import MigrationPage from './components/MigrationPage';

app.initializers.add('t11n-dev/flarum-migration-tool', () => {
  app.extensionData
    .for('t11n-dev-migration-tool')
    .registerPage(MigrationPage);
});
