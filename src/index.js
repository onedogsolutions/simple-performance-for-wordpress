import { render } from '@wordpress/element';
import App from './components/App';
import './styles/index.css';

const rootElement = document.getElementById( 'spfw-admin-root' );

if ( rootElement ) {
	render( <App />, rootElement );
}
