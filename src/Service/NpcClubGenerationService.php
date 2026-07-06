<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\FacilityTemplate;
use App\Entity\NpcClub;
use App\Repository\FacilityTemplateRepository;
use App\Repository\GameConfigRepository;
use App\Repository\NpcClubRepository;
use App\Service\LeagueService;
use Doctrine\ORM\EntityManagerInterface;

class NpcClubGenerationService
{
    // ── Place names by ISO country code ──────────────────────────────────────
    private const PLACE_NAMES_BY_COUNTRY = [
        'ES' => [
            'Madrid', 'Barcelona', 'Valencia', 'Sevilla', 'Zaragoza',
            'Málaga', 'Murcia', 'Palma', 'Las Palmas', 'Bilbao',
            'Alicante', 'Córdoba', 'Valladolid', 'Vigo', 'Gijón',
            "L'Hospitalet", 'Vitoria', 'A Coruña', 'Elche', 'Granada',
            'Terrassa', 'Badalona', 'Cartagena', 'Sabadell', 'Oviedo',
            'Jerez', 'Móstoles', 'Santa Cruz', 'Pamplona', 'Almería',
            'Alcalá de Henares', 'Fuenlabrada', 'Leganés', 'Donostia', 'Getafe',
            'Burgos', 'Albacete', 'Castellón', 'Santander', 'Alcorcón',
            'San Cristóbal', 'Logroño', 'Badajoz', 'Huelva', 'Salamanca',
            'Marbella', 'Lleida', 'Dos Hermanas', 'Tarragona', 'Torrejón',
            'Parla', 'Mataró', 'Algeciras', 'León', 'Santa Coloma',
            'Alcobendas', 'Cádiz', 'Jaén', 'Ourense', 'Reus',
            'Telde', 'Barakaldo', 'Lugo', 'Girona', 'Santiago',
            'Cáceres', 'Las Rozas', 'Lorca', 'San Fernando', 'San Cugat',
            'San Sebastián de los Reyes', 'Rivas-Vaciamadrid', 'Cornellà', 'El Puerto de Santa María', 'Guadalajara',
            'Pozuelo', 'Toledo', 'Mijas', 'Chiclana', 'Melilla',
            'Torrent', 'Ceuta', 'Sant Boi', 'Talavera', 'Pontevedra',
            'Fuengirola', 'Arona', 'Coslada', 'Orihuela', 'Rubí',
            'Manresa', 'Agüimes', 'Valdemoro', 'Getxo', 'Avilés',
            'Gandia', 'Alcalá de Guadaíra', 'Estepona', 'Benidorm', 'Majadahonda',
            'Molina de Segura', 'Santa Lucía de Tirajana', 'Vilanova i la Geltrú', 'Benalmádena', 'Paterna',
            'Sagunto', 'Zamora', 'Viladecans', 'La Línea', 'El Prat',
            'Castelldefels', 'Torremolinos', 'Sanlúcar', 'Arrecife', 'Motril',
            'Roquetas de Mar', 'Collado Villalba', 'Alcoy', 'Linares', 'Irun',
            'Granollers', 'Cerdanyola', 'Aranjuez', 'Mérida', 'Ávila',
            'Cuenca', 'Segovia', 'Soria', 'Teruel', 'Huesca',
            'Elda', 'Utrera', 'Mollet del Vallès', 'Torrevieja', 'Ponferrada',
            'Villarreal', 'Arganda del Rey', 'Boadilla del Monte', 'Pinto', 'Colmenar Viejo',
            'Tres Cantos', 'San Vicente del Raspeig', 'Adeje', 'Vic', 'Blanes',
            'Vilafranca del Penedès', 'Miranda de Ebro', 'Antequera', 'Ronda', 'Andújar',
            'Plasencia', 'Don Benito', 'Almendralejo', 'Villanueva de la Serena', 'Puertollano',
            'Tomelloso', 'Alcázar de San Juan', 'Valdepeñas', 'Hellín', 'Villarrobledo',
            'Almansa', 'Yecla', 'Totana', 'Mazarrón', 'Cieza',
            'Caravaca de la Cruz', 'Jumilla', 'Águilas', 'San Javier', 'Torre-Pacheco',
            'Denia', 'Jávea', 'Calpe', 'Altea', 'Villajoyosa',
            'Crevillente', 'Santa Pola', 'Novelda', 'Aspe', 'Ibi',
            'Ontinyent', 'Xàtiva', 'Alzira', 'Sueca', 'Cullera',
            'Gandia', 'Oliva', 'Burriana', 'Vinaròs', 'Benicarló',
            'Onda', 'Vall de Uxó', 'Nules', 'Almassora', 'Tudela',
            'Estella', 'Tafalla', 'Corella', 'Calahorra', 'Arnedo',
            'Alfaro', 'Haro', 'Santo Domingo de la Calzada', 'Nájera', 'Ejea de los Caballeros',
            'Tarazona', 'Calatayud', 'Barbastro', 'Monzón', 'Fraga',
            'Jaca', 'Sabiñánigo', 'Binéfar', 'Alcañiz', 'Andorra de Teruel',
            'Tortosa', 'Amposta', 'Valls', 'Vendrell', 'Calafell',
            'Cambrils', 'Salou', 'Vila-seca', 'Igualada', 'Martorell',
            'Esparreguera', 'Olot', 'Figueres', 'Salt', 'Palafrugell',
            'Lloret de Mar', 'Sant Feliu de Guíxols', 'Banyoles', 'Ripoll', 'Mollerussa',
            'Tàrrega', 'Balaguer', "La Seu d'Urgell", 'Cervera', 'Guissona',
            'Manzanares', 'Daimiel', 'La Roda', 'Tarancón', 'Illescas',
            'Seseña', 'Torrijos', 'Sonseca', 'Quintanar de la Orden', 'Madridejos',
            'Azuqueca de Henares', 'Alovera', 'Marchamalo', 'Sigüenza', 'Aranda de Duero',
            'Medina del Campo', 'Tordesillas', 'Laguna de Duero', 'Arroyo de la Encomienda', 'Toro',
            'Benavente', 'Astorga', 'La Bañeza', 'Villablino', 'Bembibre',
            'Guardo', 'Aguilar de Campoo', 'Venta de Baños', 'Arévalo', 'Cuéllar',
            'El Espinar', 'Palazuelos de Eresma', 'Burgo de Osma', 'Almazán', 'Navalmoral de la Mata',
            'Coria', 'Trujillo', 'Mia聚adas', 'Talayuela', 'Zafra',
            'Olivenza', 'Jerez de los Caballeros', 'Montijo', 'Azuaga', 'Villafranca de los Barros',
            'Ribeira', 'Boiro', 'Noia', 'A Pobra do Caramiñal', 'Rianxo',
            'Bertamiráns', 'Milladoiro', 'Ordes', 'Carballo', 'Laracha',
            'Arteixo', 'Culleredo', 'Oleiros', 'Cambre', 'Sada',
            'Betanzos', 'Pontedeume', 'Fene', 'Narón', 'Ferrol',
            'As Pontes', 'Viveiro', 'Burela', 'Foz', 'Ribadeo',
            'Vilalba', 'Sarria', 'Chantada', 'Monforte de Lemos', 'Verín',
            'O Carballiño', 'Xinzo de Limia', 'Barbadás', 'O Porriño', 'Tui',
            'A Guarda', 'Baiona', 'Gondomar', 'Nigrán', 'Redondela',
            'Marín', 'Cangas', 'Moaña', 'Bueu', 'Sanxenxo'
        ],
        'EN' => [
            'London', 'Birmingham', 'Manchester', 'Liverpool', 'Leeds',
            'Sheffield', 'Bristol', 'Newcastle', 'Sunderland', 'Wolverhampton',
            'Nottingham', 'Leicester', 'Southampton', 'Portsmouth', 'Norwich',
            'Coventry', 'Hull', 'Stoke', 'Plymouth', 'Derby',
            'Reading', 'Brighton', 'Bournemouth', 'Middlesbrough', 'Blackpool',
            'Huddersfield', 'Ipswich', 'York', 'Gloucester', 'Cambridge',
            'Oxford', 'Milton Keynes', 'Northampton', 'Luton', 'Swindon',
            'Warrington', 'Telford', 'Peterborough', 'Chelmsford', 'Colchester',
            'Basildon', 'Crawley', 'Slough', 'Exeter', 'Chester',
            'Doncaster', 'Rotherham', 'Stockport', 'Oldham', 'Bolton',
            'Wigan', 'Preston', 'Blackburn', 'Burnley', 'Rochdale',
            'Salford', 'Crewe', 'Carlisle', 'Shrewsbury', 'Grimsby',
            'Scunthorpe', 'Lincoln', 'Mansfield', 'Chesterfield', 'Worcester',
            'Hereford', 'Bath', 'Taunton', 'Yeovil', 'Torquay',
            'Barnstaple', 'Weymouth', 'Salisbury', 'Basingstoke', 'Winchester',
            'Aldershot', 'Farnborough', 'Guildford', 'Woking', 'Maidstone',
            'Gillingham', 'Chatham', 'Canterbury', 'Dover', 'Folkestone',
            'Ashford', 'Hastings', 'Eastbourne', 'Worthing', 'Chichester',
            'Horsham', 'Harlow', 'St Albans', 'Stevenage', 'Watford',
            'Hemel Hempstead', 'Bedford', 'Rugby', 'Leamington Spa', 'Warwick',
            'Stratford-upon-Avon', 'Solihull', 'Tamworth', 'Burton upon Trent', 'Stafford',
            'Cannock', 'Lichfield', 'Kidderminster', 'Redditch', 'Halesowen',
            'Dudley', 'Walsall', 'Stourbridge', 'West Bromwich', 'Smethwick',
            'Sutton Coldfield', 'Nuneaton', 'Hinckley', 'Loughborough', 'Melton Mowbray',
            'Coalville', 'Boston', 'Grantham', 'Skegness', 'Spalding',
            'Stamford', 'Gainsborough', 'Retford', 'Worksop', 'Newark-on-Trent',
            'Kettering', 'Corby', 'Wellingborough', 'Daventry', 'Rushden',
            'Banbury', 'Bicester', 'Witney', 'Abingdon', 'Didcot',
            'High Wycombe', 'Aylesbury', 'Amersham', 'Chesham', 'Marlow',
            'Beaconsfield', 'Bracknell', 'Maidenhead', 'Windsor', 'Newbury',
            'Wokingham', 'Reigate', 'Redhill', 'Epsom', 'Staines',
            'Sunbury', 'Weybridge', 'Ewell', 'Camberley', 'Dorking',
            'Sevenoaks', 'Tonbridge', 'Tunbridge Wells', 'Dartford', 'Gravesend',
            'Sittingbourne', 'Faversham', 'Herne Bay', 'Whitstable', 'Margate',
            'Ramsgate', 'Broadstairs', 'Deal', 'Burgess Hill', 'Haywards Heath',
            'Shoreham-by-Sea', 'Littlehampton', 'Bognor Regis', 'Gosport', 'Fareham',
            'Havant', 'Waterlooville', 'Eastleigh', 'Andover', 'Fleet',
            'Trowbridge', 'Chippenham', 'Devizes', 'Warminster', 'Melksham',
            'Cirencester', 'Stroud', 'Cheltenham', 'Tewkesbury', 'Bridgwater',
            'Weston-super-Mare', 'Clevedon', 'Portishead', 'Burnham-on-Sea', 'Frome',
            'Bridport', 'Poole', 'Christchurch', 'Ferndown', 'Wimborne Minster',
            'Dorchester', 'Exmouth', 'Tiverton', 'Newton Abbot', 'Paignton',
            'Brixham', 'Truro', 'Falmouth', 'Penzance', 'St Austell',
            'Newquay', 'Camborne', 'Redruth', 'Bodmin', 'Saltash',
            'Barrow-in-Furness', 'Kendal', 'Whitehaven', 'Workington', 'Penrith',
            'Lancaster', 'Morecambe', 'Fleetwood', 'Lytham St Annes', 'Chorley',
            'Leyland', 'Skelmersdale', 'Ormskirk', 'St Helens', 'Southport',
            'Bootle', 'Birkenhead', 'Wallasey', 'Ellesmere Port', 'Bebington',
            'Runcorn', 'Widnes', 'Macclesfield', 'Winsford', 'Northwich',
            'Congleton', 'Wilmslow', 'Knutsford', 'Altrincham', 'Sale',
            'Stretford', 'Urmston', 'Eccles', 'Swinton', 'Prestwich',
            'Whitefield', 'Radcliffe', 'Bury', 'Middleton', 'Heywood',
            'Ashton-under-Lyne', 'Stalybridge', 'Hyde', 'Dukinfield', 'Droylsden',
            'Glossop', 'Buxton', 'Matlock', 'Ilkeston', 'Long Eaton',
            'Beeston', 'West Bridgford', 'Arnold', 'Carlton', 'Hucknall',
            'Kirkby-in-Ashfield', 'Sutton-in-Ashfield', 'Ripley', 'Heanor', 'Belper'
        ],
        'DE' => [
            'Berlin', 'Hamburg', 'München', 'Köln', 'Frankfurt',
            'Stuttgart', 'Düsseldorf', 'Dortmund', 'Essen', 'Leipzig',
            'Bremen', 'Dresden', 'Hannover', 'Nürnberg', 'Duisburg',
            'Bochum', 'Wuppertal', 'Bielefeld', 'Bonn', 'Münster',
            'Karlsruhe', 'Mannheim', 'Augsburg', 'Wiesbaden', 'Gelsenkirchen',
            'Mönchengladbach', 'Braunschweig', 'Chemnitz', 'Kiel', 'Aachen',
            'Halle', 'Magdeburg', 'Freiburg', 'Krefeld', 'Lübeck',
            'Mainz', 'Erfurt', 'Oberhausen', 'Rostock', 'Kassel',
            'Hagen', 'Hamm', 'Saarbrücken', 'Mülheim an der Ruhr', 'Potsdam',
            'Ludwigshafen', 'Oldenburg', 'Leverkusen', 'Osnabrück', 'Solingen',
            'Heidelberg', 'Herne', 'Neuss', 'Darmstadt', 'Paderborn',
            'Regensburg', 'Ingolstadt', 'Würzburg', 'Fürth', 'Wolfsburg',
            'Offenbach', 'Ulm', 'Heilbronn', 'Pforzheim', 'Göttingen',
            'Bottrop', 'Recklinghausen', 'Reutlingen', 'Koblenz', 'Bergisch Gladbach',
            'Erlangen', 'Remscheid', 'Jena', 'Trier', 'Salzgitter',
            'Moers', 'Siegen', 'Hildesheim', 'Cottbus', 'Gütersloh',
            'Kaiserslautern', 'Witten', 'Hanau', 'Schwerin', 'Gießen',
            'Esslingen', 'Iserlohn', 'Düren', 'Ratingen', 'Konstanz',
            'Ludwigsburg', 'Tübingen', 'Wetzlar', 'Zwickau', 'Flensburg',
            'Wilhelmshaven', 'Gera', 'Görlitz', 'Plauen', 'Dessau',
            'Brandenburg an der Havel', 'Lüneburg', 'Gießen', 'Marburg', 'Fulda',
            'Bamberg', 'Bayreuth', 'Landshut', 'Passau', 'Rosenheim',
            'Kempten', 'Neu-Ulm', 'Friedrichshafen', 'Villingen-Schwenningen', 'Offenburg',
            'Baden-Baden', 'Sindelfingen', 'Göppingen', 'Waiblingen', 'Rüsselsheim',
            'Bad Homburg', 'Oberursel', 'Hanau', 'Rodgau', 'Dreieich',
            'Langen', 'Neu-Isenburg', 'Bad Vilbel', 'Friedberg', 'Bensheim',
            'Heppenheim', 'Worms', 'Speyer', 'Neustadt', 'Frankenthal',
            'Landau', 'Bad Kreuznach', 'Idar-Oberstein', 'Neunkirchen', 'Homburg',
            'Völklingen', 'Sankt Ingbert', 'Saarlouis', 'Merzig', 'Lörrach',
            'Rheinfelden', 'Weil am Rhein', 'Freiburg', 'Kehl', 'Lahr',
            'Rastatt', 'Bruchsal', 'Ettlingen', 'Bretten', 'Pforzheim',
            'Mühlacker', 'Sindelfingen', 'Böblingen', 'Leonberg', 'Herrenberg',
            'Bietigheim-Bissingen', 'Kornwestheim', 'Ludwigsburg', 'Vaihingen', 'Nürtingen',
            'Kirchheim unter Teck', 'Filderstadt', 'Leinfelden-Echterdingen', 'Ostfildern', 'Schorndorf',
            'Winnenden', 'Backnang', 'Fellbach', 'Waiblingen', 'Weinstadt',
            'Remseck', 'Aalen', 'Schwäbisch Gmünd', 'Heidenheim', 'Ellwangen',
            'Crailsheim', 'Schwäbisch Hall', 'Bad Mergentheim', 'Wertheim', 'Neckarsulm',
            'Öhringen', 'Mosbach', 'Sinsheim', 'Wiesloch', 'Walldorf',
            'Hockenheim', 'Weinheim', 'Viernheim', 'Lampertheim', 'Schwetzingen',
            'Eppingen', 'Bad Rappenau', 'Bretten', 'Stutensee', 'Waghäusel',
            'Dinkelsbühl', 'Ansbach', 'Schwabach', 'Roth', 'Zirndorf',
            'Stein', 'Herzogenaurach', 'Forchheim', 'Erlangen', 'Lauf an der Pegnitz',
            'Altdorf', 'Amberg', 'Weiden', 'Schwandorf', 'Neumarkt',
            'Kelheim', 'Straubing', 'Deggendorf', 'Vilshofen', 'Pocking',
            'Burghausen', 'Altötting', 'Mühldorf', 'Waldkraiburg', 'Wasserburg',
            'Traunstein', 'Traunreut', 'Bad Reichenhall', 'Freilassing', 'Garmisch-Partenkirchen',
            'Weilheim', 'Penzberg', 'Geretsried', 'Starnberg', 'Germering',
            'Fürstenfeldbruck', 'Olching', 'Puchheim', 'Dachau', 'Freising',
            'Erding', 'Unterschleißheim', 'Garching', 'Ismaning', 'Haar',
            'Vaterstetten', 'Ottobrunn', 'Taufkirchen', 'Unterhaching', 'Grünwald',
            'Planegg', 'Gräfelfing', 'Karlsfeld', 'Eichenau', 'Gröbenzell',
            'Maisach', 'Markt Indersdorf', 'Pfaffenhofen', 'Schrobenhausen', 'Neuburg',
            'Donauwörth', 'Nördlingen', 'Günzburg', 'Dillingen', 'Memmingen',
            'Mindelheim', 'Bad Wörishofen', 'Kaufbeuren', 'Sonthofen', 'Immenstadt',
            'Lindau', 'Wangen', 'Ravensburg', 'Weingarten', 'Biberach',
            'Laupheim', 'Ehingen', 'Metzingen', 'Nürtingen', 'Rottenburg',
            'Balingen', 'Albstadt', 'Hechingen', 'Sigmaringen', 'Rottweil',
            'Tuttlingen', 'Schramberg', 'Donaueschingen', 'Bad Krozingen', 'Müllheim'
        ],
        'IT' => [
            'Roma', 'Milano', 'Napoli', 'Torino', 'Palermo',
            'Genova', 'Bologna', 'Firenze', 'Bari', 'Catania',
            'Verona', 'Venezia', 'Messina', 'Padova', 'Trieste',
            'Taranto', 'Brescia', 'Parma', 'Prato', 'Modena',
            'Reggio Calabria', 'Reggio Emilia', 'Perugia', 'Ravenna', 'Livorno',
            'Cagliari', 'Foggia', 'Rimini', 'Salerno', 'Ferrara',
            'Sassari', 'Latina', 'Giugliano', 'Monza', 'Siracusa',
            'Pescara', 'Bergamo', 'Forlì', 'Trento', 'Vicenza',
            'Terni', 'Bolzano', 'Novara', 'Piacenza', 'Ancona',
            'Andria', 'Arezzo', 'Udine', 'Cesena', 'Lecce',
            'Pesaro', 'Barletta', 'Alessandria', 'La Spezia', 'Pistoia',
            'Pisa', 'Catanzaro', 'Guastalla', 'Lucca', 'Brindisi',
            'Torre del Greco', 'Como', 'Treviso', 'Marsala', 'Busto Arsizio',
            'Varese', 'Grosseto', 'Casoria', 'Sesto San Giovanni', 'Gela',
            'Cinisello Balsamo', 'Caserta', 'Asti', 'Ragusa', "L'Aquila",
            "Quartu Sant'Elena", 'Castellammare di Stabia', 'Aprilia', 'Pavia', 'Viterbo',
            'Massa', 'Potenza', 'Crotone', 'Cosenza', 'Nuoro',
            'Oristano', 'Carbonia', 'Iglesias', 'Sanluri', 'Villacidro',
            'Tempio Pausania', 'Olbia', 'Lanusei', 'Tortolì', 'Agrigento',
            'Caltanissetta', 'Enna', 'Trapani', 'Vibo Valentia', 'Benevento',
            'Avellino', 'Isernia', 'Campobasso', 'Chieti', 'Teramo',
            'Ascoli Piceno', 'Fermo', 'Macerata', 'Rovigo', 'Belluno',
            'Gorizia', 'Pordenone', 'Sondrio', 'Lecco', 'Lodi',
            'Cremona', 'Mantova', 'Vercelli', 'Biella', 'Verbania',
            'Imperia', 'Savona', 'Massa-Carrara', 'Siena', 'Grosseto',
            'Rieti', 'Frosinone', 'Latina', 'Civitavecchia', 'Fiumicino',
            'Pomezia', 'Anzio', 'Nettuno', 'Velletri', 'Tivoli',
            'Guidonia', 'Monterotondo', 'Albano Laziale', 'Marino', 'Frascati',
            'Ciampino', 'Ardea', 'Cerveteri', 'Ladispoli', 'Viareggio',
            'Forte dei Marmi', 'Pietrasanta', 'Camaiore', 'Massarosa', 'Seravezza',
            'Carrara', 'Aulla', 'Pontremoli', 'Fivizzano', 'Empoli',
            'Sesto Fiorentino', 'Campi Bisenzio', 'Scandicci', 'Bagno a Ripoli', 'Lastra a Signa',
            'Signa', 'Figline Valdarno', 'Pontassieve', 'Borgo San Lorenzo', 'Castelfiorentino',
            'Certaldo', 'Fucecchio', 'San Miniato', 'Pontedera', 'Cascina',
            'San Giuliano Terme', 'Rosignano Marittimo', 'Cecina', 'Piombino', 'Portoferraio',
            'Montevarchi', 'San Giovanni Valdarno', 'Cortona', 'Sansepolcro', 'Bibbiena',
            'Poggibonsi', "Colle di Val d'Elsa", 'Chiusi', 'Montepulciano', 'Chianciano Terme',
            'Orbetello', 'Monte Argentario', 'Città di Castello', 'Gubbio', 'Foligno',
            'Spoleto', 'Assisi', 'Bastia Umbra', 'Todi', 'Orvieto',
            'Amelia', 'Narni', 'Fabriano', 'Jesi', 'Senigallia',
            'Falconara Marittima', 'Osimo', 'Castelfidardo', 'Loreto', 'Civitanova Marche',
            'Recanati', 'Tolentino', 'San Severino Marche', "Porto Sant'Elpidio", "Sant'Elpidio a Mare",
            'Porto San Giorgio', 'Grottammare', 'San Benedetto del Tronto', 'Alba Adriatica', 'Giulianova',
            'Roseto degli Abruzzi', 'Pineto', 'Silvi', 'Montesilvano', 'Spoltore',
            'Francavilla al Mare', 'Ortona', 'Lanciano', 'Vasto', 'San Salvo',
            'Termoli', 'Avezzano', 'Sulmona', 'Celano', 'Genzano di Roma',
            'Ariccia', 'Rocca di Papa', 'Grottaferrata', 'Valmontone', 'Colleferro',
            'Palestrina', 'Zagarolo', 'San Cesareo', 'Sora', 'Cassino',
            'Isola del Liri', 'Alatri', 'Anagni', 'Ferentino', 'Ceccano',
            'Terracina', 'Fondi', 'Formia', 'Gaeta', 'Minturno',
            'Itri', 'Sperlonga', 'San Felice Circeo', 'Sabaudia', 'Pontinia',
            'Cisterna di Latina', 'Cori', 'Sermoneta', 'Norma', 'Sezze',
            'Priverno', 'Maenza', 'Roccagorga', 'Sonnino', 'Aversa',
            'Marcianise', 'Maddaloni', 'Santa Maria Capua Vetere', 'Capua', 'Casal di Principe',
            'Castel Volturno', 'Mondragone', 'Sessa Aurunca', 'Teano', 'Piedimonte Matese',
            'Acerra', 'Afragola', 'Arzano', 'Caivano', 'Cardito',
            'Casavatore', 'Casalnuovo di Napoli', "Pomigliano d'Arco", "Sant'Antimo", 'Frattamaggiore',
            'Grumo Nevano', 'Melito di Napoli', 'Mugnano di Napoli', 'Marano di Napoli', 'Qualiano',
            'Villaricca', 'Pozzuoli', 'Bacoli', 'Monte di Procida', 'Quarto',
            'Portici', 'Ercolano', 'San Giorgio a Cremano', 'San Sebastiano al Vesuvio', 'Cercola',
            'Massa di Somma', 'Pollena Trocchia', "Sant'Anastasia", 'Somma Vesuviana', 'Ottaviano',
            'San Giuseppe Vesuviano', 'Palma Campania', 'Nola', 'Saviano', 'Marigliano',
            'Brusciano', 'Castello di Cisterna', 'Torre Annunziata', 'Pompei', 'Boscoreale',
            'Boscotrecase', 'Trecase', 'Castellammare di Stabia', 'Gragnano', 'Santa Maria la Carità'
        ],
        'FR' => [
            'Paris', 'Marseille', 'Lyon', 'Toulouse', 'Nice',
            'Nantes', 'Montpellier', 'Strasbourg', 'Bordeaux', 'Lille',
            'Rennes', 'Reims', 'Saint-Étienne', 'Le Havre', 'Toulon',
            'Grenoble', 'Dijon', 'Angers', 'Nîmes', 'Villeurbanne',
            'Mulhouse', 'Rouen', 'Clermont-Ferrand', 'Brest', 'Caen',
            'Orléans', 'Metz', 'Boulogne-Billancourt', 'Perpignan', 'Besançon',
            'Amiens', 'Annecy', 'Limoges', 'Argenteuil', 'Poitiers',
            'Saint-Denis', 'Dunkerque', 'Versailles', 'Courbevoie', 'Béziers',
            'La Rochelle', 'Pau', 'Calais', 'Antibes', 'Cannes',
            'Tourcoing', 'Mérignac', 'Saint-Paul', 'Colmar', 'Issy-les-Moulineaux',
            'Rueil-Malmaison', 'Venissieux', 'Levallois-Perret', 'Nancy', 'Bourges',
            'Champigny-sur-Marne', 'Pessac', 'Roubaix', 'Quimper', 'Ivry-sur-Seine',
            'Lorient', 'Aubervilliers', 'Noisy-le-Grand', 'La Seyne-sur-Mer', 'Sarcelles',
            'Cergy', 'Clichy', 'Niort', 'Saint-Quentin', 'Chambéry',
            'Montauban', 'Hyères', 'Beauvais', 'Cholet', 'Vannes',
            'Évreux', 'Saint-Brieuc', 'Châteauroux', 'Albi', 'Brive-la-Gaillarde',
            'Carcassonne', 'Gap', 'Bastia', 'Ajaccio', 'Arles',
            'Tarbes', 'Blois', 'Agen', 'Belfort', 'Charleville-Mézières',
            'Mantes-la-Jolie', 'Narbonne', 'Saint-Malo', 'Castres', 'Vichy',
            'Mont-de-Marsan', 'Sète', 'Valence', 'Auxerre', 'Épinal',
            'Dieppe', 'Thionville', 'Cambrai', 'Douai', 'Maubeuge',
            'Arras', 'Lens', 'Liévin', 'Hénin-Beaumont', 'Valenciennes',
            'Bethune', 'Boulogne-sur-Mer', 'Saint-Omer', 'Hazebrouck', "Villeneuve-d'Ascq",
            'Marcq-en-Baroeul', 'Lambersart', 'Madeleine', 'Mons-en-Baroeul', 'Croix',
            'Wasquehal', 'Halluin', 'Roncq', 'Neuville-en-Ferrain', 'Lys-lez-Lannoy',
            'Mouvaux', 'Seclin', 'Hem', 'Wattrelos', 'Tourcoing',
            'Dunkerque', 'Grande-Synthe', 'Coudekerque-Branche', 'Saint-Pol-sur-Mer', 'Gravelines',
            'Loon-Plage', 'Calais', 'Marck', 'Guînes', 'Ardres',
            'Montreuil-sur-Mer', 'Berck', 'Étaples', 'Le Touquet', 'Lens',
            'Avion', 'Méricourt', 'Sallaumines', 'Mazingarbe', 'Grenay',
            'Bully-les-Mines', 'Noeux-les-Mines', 'Bruay-la-Buissière', 'Auchel', 'Marles-les-Mines',
            'Lillers', 'Béthune', 'Beuvry', 'Annezin', 'Verquigneul',
            'Arras', 'Saint-Nicolas', 'Saint-Laurent-Blangy', 'Achicourt', 'Beaurains',
            'Dainville', 'Bapaume', 'Saint-Pol-sur-Ternoise', 'Hesdin', 'Frévent',
            'Amiens', 'Abbeville', 'Péronne', 'Albert', 'Ham',
            'Montdidier', 'Roye', 'Corbie', 'Longueau', 'Camon',
            'Saint-Quentin', 'Soissons', 'Laon', 'Tergnier', 'Chauny',
            'Château-Thierry', 'Villers-Cotterêts', 'Hirson', 'Guise', 'Vervins',
            'Beauvais', 'Compiègne', 'Creil', 'Nogent-sur-Oise', 'Senlis',
            'Noyon', 'Chantilly', 'Clermont', 'Pont-Sainte-Maxence', 'Méru',
            'Châlons-en-Champagne', 'Épernay', 'Vitry-le-François', 'Sainte-Menehould', 'Reims',
            'Tinqueux', 'Bétheny', 'Cormontreuil', 'Saint-Brice-Courcelles', 'Fismes',
            'Charleville-Mézières', 'Sedan', 'Rethel', 'Revin', 'Givet',
            'Nouzonville', 'Bogny-sur-Meuse', 'Vouziers', 'Carignan', 'Fumay',
            'Troyes', 'Romilly-sur-Seine', 'La Chapelle-Saint-Luc', 'Saint-André-les-Vergers', 'Sainte-Savine',
            'Bar-sur-Aube', 'Bar-sur-Seine', 'Nogent-sur-Seine', 'Brienne-le-Château', 'Arcis-sur-Aube',
            'Chaumont', 'Saint-Dizier', 'Langres', 'Joinville', 'Wassy',
            'Nogent', 'Bourbonne-les-Bains', 'Chalindrey', 'Fayl-Billot', 'Montier-en-Der',
            'Auxerre', 'Sens', 'Joigny', 'Migennes', 'Avallon',
            'Tonnerre', 'Villeneuve-sur-Yonne', 'Saint-Florentin', 'Charny', 'Toucy',
            'Dijon', 'Beaune', 'Chenôve', 'Talant', 'Quetigny',
            'Chevigny-Saint-Sauveur', 'Longvic', 'Marsannay-la-Côte', 'Saint-Apollinaire', 'Fontaine-lès-Dijon',
            'Montbard', 'Châtillon-sur-Seine', 'Semur-en-Auxois', 'Genlis', 'Auxonne',
            'Nuits-Saint-Georges', 'Gevrey-Chambertin', 'Is-sur-Tille', 'Saulieu', 'Vitteaux',
            'Nevers', 'Cosne-Cours-sur-Loire', 'Varennes-Vauzelles', 'Decize', 'La Charité-sur-Loire',
            'Fourchambault', 'Imphy', 'Clamecy', 'Château-Chinon', 'Luzy',
            'Mâcon', 'Chalon-sur-Saône', 'Le Creusot', 'Montceau-les-Mines', 'Autun',
            'Paray-le-Monial', 'Digoin', 'Gueugnon', 'Tournus', 'Louhans',
            'Besançon', 'Montbéliard', 'Pontarlier', 'Audincourt', 'Valentigney',
            'Bethoncourt', 'Morteau', 'Maîche', 'Baume-les-Dames', 'Valdahon',
            'Lons-le-Saunier', 'Dole', 'Saint-Claude', 'Champagnole', 'Morez',
            'Poligny', 'Arbois', 'Salins-les-Bains', 'Tavaux', 'Saint-Amour',
            'Vesoul', 'Héricourt', 'Lure', 'Luxeuil-les-Bains', 'Gray',
            'Fougerolles', 'Saint-Loup-sur-Semouse', 'Champagney', 'Ronchamp', 'Port-sur-Saône',
            'Belfort', 'Delle', 'Valdoie', 'Beaucourt', 'Bavilliers',
            'Offemont', 'Danjoutin', 'Giromagny', 'Grandvillars', 'Essert'
        ],
        'BR' => [
            'São Paulo', 'Rio de Janeiro', 'Brasília', 'Salvador', 'Fortaleza',
            'Belo Horizonte', 'Manaus', 'Curitiba', 'Recife', 'Goiânia',
            'Belém', 'Porto Alegre', 'Guarulhos', 'Campinas', 'São Luís',
            'São Gonçalo', 'Maceió', 'Duque de Caxias', 'Natal', 'Teresina',
            'São Bernardo do Campo', 'Nova Iguaçu', 'João Pessoa', 'Santo André', 'São José dos Campos',
            'Jaboatão dos Guararapes', 'Uberlândia', 'Osasco', 'Ribeirão Preto', 'Cuiabá',
            'Sorocaba', 'Aracaju', 'Feira de Santana', 'Londrina', 'Joinville',
            'Ananindeua', 'Niterói', 'Belford Roxo', 'Caxias do Sul', 'Campos dos Goytacazes',
            'Vila Velha', 'Florianópolis', 'Macapá', 'Mauá', 'São José do Rio Preto',
            'Santos', 'Mogi das Cruzes', 'Betim', 'Diadema', 'Campina Grande',
            'Jundiaí', 'Maringá', 'Montes Claros', 'Piracicaba', 'Carapicuíba',
            'Olinda', 'Cariacica', 'Rio Branco', 'Anápolis', 'Bauru',
            'Vitória', 'Caucaia', 'Itaquaquecetuba', 'São Vicente', 'Vitória da Conquista',
            'Caruaru', 'Blumenau', 'Ponta Grossa', 'Petrolina', 'Canoas',
            'Pelotas', 'Franca', 'Paulista', 'Ribeirão das Neves', 'Uberaba',
            'Boa Vista', 'Cascavel', 'Guarujá', 'Taubaté', 'Petrópolis',
            'Limeira', 'Praia Grande', 'São José dos Pinhais', 'Santarém', 'Mossoró',
            'Suzano', 'Palmas', 'Camaçari', 'Governador Valadares', 'Santa Maria',
            'Gravataí', 'Taboão da Serra', 'Várzea Grande', 'Juazeiro do Norte', 'Sumaré',
            'Foz do Iguaçu', 'Marabá', 'Barueri', 'Embu das Artes', 'Cabo de Santo Agostinho',
            'Imperatriz', 'Volta Redonda', 'Ipatinga', 'Parnamirim', 'Mogi Guaçu',
            'São Leopoldo', 'Jacareí', 'Colombo', 'Indaiatuba', 'Rondonópolis',
            'Castanhal', 'Araraquara', 'Sete Lagoas', 'Marília', 'Itaboraí',
            'Aparecida de Goiânia', 'Águas Lindas de Goiás', 'Novo Hamburgo', 'Dourados', 'São Carlos',
            'Passo Fundo', 'São José', 'Ilhéus', 'Juazeiro', 'Macau',
            'Tubarão', 'Rio Grande', 'Lages', 'Chapecó', 'Criciúma',
            'Jaraguá do Sul', 'Palhoça', 'Brusque', 'Balneário Camboriú', 'Uruguaiana',
            'Santa Cruz do Sul', 'Bagé', 'Bento Gonçalves', 'Erechim', 'Cachoeirinha',
            'Guaíba', 'Viamão', 'Alvorada', 'Sapucaia do Sul', 'Esteio',
            'Cachoeira do Sul', 'Santana do Livramento', 'Ijuí', 'Cruz Alta', 'Alegrete',
            'Luziana', 'Valparaíso de Goiás', 'Senador Canedo', 'Trindade', 'Itumbiara',
            'Jataí', 'Rio Verde', 'Caldas Novas', 'Catalão', 'Formosa',
            'Planaltina', 'Sobradinho', 'Gama', 'Ceilândia', 'Taguatinga',
            'Samambaia', 'Recanto das Emas', 'Santa Maria', 'Guará', 'Vicente Pires',
            'Viana', 'Guarapari', 'Serra', 'Linhares', 'Colatina',
            'São Mateus', 'Aracruz', 'Cachoeiro de Itapemirim', 'Itapemirim', 'Marataízes',
            'Resende', 'Barra Mansa', 'Angra dos Reis', 'Paraty', 'Cabo Frio',
            'Arraial do Cabo', 'Búzios', 'Macaé', 'Rio das Ostras', 'Itaperuna',
            'Nova Friburgo', 'Teresópolis', 'Três Rios', 'Maricá', 'Saquarema',
            'Araruama', 'Itaguaí', 'Seropédica', 'Queimados', 'Mesquita',
            'Nilópolis', 'São João de Meriti', 'Magé', 'Guapimirim', 'Cachoeiras de Macacu',
            'Casimiro de Abreu', 'Rio Bonito', 'Silva Jardim', 'Piraí', 'Valença',
            'Barra do Piraí', 'Vassouras', 'Mendes', 'Engenheiro Paulo de Frontin', 'Miguel Pereira',
            'Paty do Alferes', 'Paracambi', 'Japeri', 'Itaguaí', 'Mangaratiba',
            'Uberlândia', 'Uberaba', 'Araguari', 'Patos de Minas', 'Ituiutaba',
            'Frutal', 'Monte Carmelo', 'Araxa', 'Ibiá', 'Patrocínio',
            'Passos', 'São Sebastião do Paraíso', 'Pouso Alegre', 'Poços de Caldas', 'Varginha',
            'Itajubá', 'Lavras', 'Três Corações', 'Alfenas', 'Guaxupé',
            'Divinópolis', 'Itaúna', 'Nova Serrana', 'Pará de Minas', 'Bom Despacho',
            'Lagoa da Prata', 'Arcos', 'Formiga', 'Cláudio', 'Oliveira',
            'Juiz de Fora', 'Barbacena', 'Ubá', 'Muriaé', 'Viçosa',
            'Cataguases', 'Leopoldina', 'São João del Rei', 'Santos Dumont', 'Além Paraíba',
            'Ipatinga', 'Coronel Fabriciano', 'Timóteo', 'Caratinga', 'Manhuaçu',
            'Ponte Nova', 'Itabira', 'João Monlevade', 'Nova Lima', 'Sabará',
            'Santa Luzia', 'Vespasiano', 'Contagem', 'Betim', 'Ibirité',
            'Esmeraldas', 'Ribeirão das Neves', 'Justinópolis', 'Igarapé', 'São Joaquim de Bicas',
            'Curvelo', 'Diamantina', 'Sete Lagoas', 'Janaúba', 'Januária',
            'Pirapora', 'Unaí', 'Paracatu', 'São Francisco', 'Salinas',
            'Teófilo Otoni', 'Nanuque', 'Almenara', 'Araçuaí', 'Pedra Azul',
            'Guanhães', 'Conselheiro Lafaiete', 'Congonhas', 'Ouro Preto', 'Mariana',
            'Feira de Santana', 'Vitória da Conquista', 'Itabuna', 'Ilhéus', 'Juazeiro',
            'Jequié', 'Alagoinhas', 'Barreiras', 'Porto Seguro', 'Teixeira de Freitas',
            'Santo Antônio de Jesus', 'Simões Filho', 'Paulo Afonso', 'Eunápolis', 'Valença',
            'Jacobina', 'Luís Eduardo Magalhães', 'Serrinha', 'Senhor do Bonfim', 'Casa Nova'
        ],
        'AR' => [
            'Buenos Aires', 'Córdoba', 'Rosario', 'La Plata', 'Mar del Plata',
            'Salta', 'Santa Fe', 'San Juan', 'Resistencia', 'Santiago del Estero',
            'Corrientes', 'Posadas', 'Neuquén', 'San Salvador de Jujuy', 'Bahía Blanca',
            'Paraná', 'Formosa', 'San Luis', 'Catamarca', 'La Rioja',
            'Comodoro Rivadavia', 'Rio Cuarto', 'Concordia', 'San Rafael', 'Tandil',
            'Villa Mercedes', 'Olavarría', 'Pergamino', 'Reconquista', 'Zárate',
            'Rafaela', 'Junín', 'Campana', 'Gualeguaychú', 'Necochea',
            'Trelew', 'Viedma', 'General Roca', 'Cipolletti', 'Venado Tuerto',
            'Luján', 'Azul', 'Chivilcoy', 'Mercedes', 'Goya',
            'Paso de los Libres', 'Puerto Madryn', 'Esquel', 'Rawson', 'Río Gallegos',
            'Ushuaia', 'Rio Grande', 'El Calafate', 'San Carlos de Bariloche', 'Villa La Angostura',
            'San Martín de los Andes', 'Zapala', 'Cutral Có', 'Plottier', 'Centenario',
            'General Pico', 'Santa Rosa', 'Toay', 'General Acha', 'Trenque Lauquen',
            'Pehuajó', 'Bolívar', 'Nueve de Julio', 'Bragado', 'Chacabuco',
            'Lincoln', 'Junín', 'Salto', 'Arrecifes', 'Rojas',
            'Colón', 'San Nicolás', 'Ramallo', 'San Pedro', 'Baradero',
            'Campana', 'Zárate', 'Escobar', 'Pilar', 'Tigre',
            'San Fernando', 'San Isidro', 'Vicente López', 'Avellaneda', 'Lanús',
            'Lomas de Zamora', 'Quilmes', 'Berazategui', 'Florencio Varela', 'Almirante Brown',
            'Esteban Echeverría', 'Ezeiza', 'Presidente Perón', 'San Vicente', 'Cañuelas',
            'Marcos Paz', 'General Rodríguez', 'Moreno', 'Merlo', 'Ituzaingó',
            'Hurlingham', 'Morón', 'La Matanza', 'Tres de Febrero', 'San Martín',
            'José C. Paz', 'Malvinas Argentinas', 'San Miguel', 'Ensenada', 'Berisso',
            'Magdalena', 'Brandsen', 'Chascomús', 'Dolores', 'Castelli',
            'General Belgrano', 'Las Flores', 'Saladillo', 'Lobos', 'Navarro',
            'Mercedes', 'San Andrés de Giles', 'San Antonio de Areco', 'Exaltación de la Cruz', 'Capilla del Señor',
            'General Las Heras', 'Monte', 'Rauch', 'Ayacucho', 'Maipú',
            'General Madariaga', 'Pinamar', 'Villa Gesell', 'Mar del Tuyú', 'San Clemente del Tuyú',
            'Santa Teresita', 'San Bernardo', 'Mar de Ajó', 'Miramar', 'Balcarce',
            'Mar del Plata', 'Batán', 'Lobería', 'Necochea', 'Quequén',
            'San Cayetano', 'Tres Arroyos', 'Gonzales Chaves', 'Coronel Pringles', 'Coronel Suárez',
            'Pigüé', 'Saavedra', 'Puan', 'Carhué', 'Tornquist',
            'Bahía Blanca', 'Punta Alta', 'Coronel Rosales', 'Villarino', 'Patagones',
            'Villalonga', 'Stroeder', 'Carmen de Patagones', 'Monte Hermoso', 'Sierra de la Ventana',
            'Tandil', 'Juárez', 'Laprida', 'Olavarría', 'General Alvear',
            'Tapalqué', 'Azul', 'Hinojo', 'Sierras Bayas', 'Cacharí',
            'Chivilcoy', 'Suipacha', 'Alberti', 'Bragado', 'Nueve de Julio',
            'Carlos Casares', 'Pehuajó', 'Trenque Lauquen', 'Pellegrini', 'Salliqueló',
            'Tres Lomas', 'Guaminí', 'Daireaux', 'General La Madrid', 'Henderson',
            'Bolívar', 'Urdampilleta', 'Pirovano', 'San Carlos de Bolívar', 'Cura Malal',
            'Rosario', 'Villa Constitución', 'San Lorenzo', 'Capitán Bermúdez', 'Granadero Baigorria',
            'Funes', 'Roldán', 'Pérez', 'Casilda', 'Cañada de Gómez',
            'Armstrong', 'Las Parejas', 'Las Rosas', 'Venado Tuerto', 'Firmat',
            'Rufino', 'Villa Cañás', 'Wheelwright', 'Hughes', 'San Gregorio',
            'Santa Fe', 'Santo Tomé', 'Sauce Viejo', 'Rincón', 'Recreo',
            'Esperanza', 'Rafaela', 'Sunchales', 'San Cristóbal', 'Ceres',
            'Tostado', 'Vera', 'Reconquista', 'Avellaneda', 'Malabrigo',
            'Villa Ocampo', 'San Justo', 'Gálvez', 'San Jorge', 'Sastre',
            'Coronda', 'San Javier', 'Helvecia', 'San Carlos Centro', 'Gessler',
            'López', 'Angélica', 'Humberto Primo', 'Moisés Ville', 'Villa Trinidad',
            'Córdoba', 'Carlos Paz', 'Alta Gracia', 'Río Ceballos', 'Unquillo',
            'Mendiolaza', 'Villa Allende', 'La Calera', 'Salsipuedes', 'Jesús María',
            'Colonia Caroya', 'Río Segundo', 'Pilar', 'Villa del Rosario', 'Oncativo',
            'Oliva', 'James Craik', 'Villa María', 'Villa Nueva', 'Bell Ville',
            'Marcos Juárez', 'Leones', 'General Roca', 'Arroyito', 'Las Varillas',
            'San Francisco', 'Morteros', 'Brinkmann', 'Balnearia', 'Miramar de Ansenuza',
            'Río Cuarto', 'Las Higueras', 'Holmberg', 'Sampacho', 'Vicuña Mackenna',
            'General Deheza', 'General Cabrera', 'Hernando', 'Almafuerte', 'Río Tercero',
            'Embalse', 'Santa Rosa de Calamuchita', 'Villa General Belgrano', 'Villa Dolores', 'Mina Clavero',
            'Cura Brochero', 'Nono', 'Cruz del Eje', 'Cosquín', 'La Falda',
            'Capilla del Monte', 'Villa Giardino', 'Valle Hermoso', 'Huerta Grande', 'Bialet Massé',
            'Tanti', 'Icho Cruz', 'Mayu Sumaj', 'San Antonio de Arredondo', 'Cuesta Blanca'
        ],
        'NL' => [
            'Amsterdam', 'Rotterdam', 'Den Haag', 'Utrecht', 'Eindhoven',
            'Groningen', 'Tilburg', 'Almere', 'Breda', 'Nijmegen',
            'Apeldoorn', 'Haarlem', 'Enschede', 'Arnhem', 'Amersfoort',
            'Zaanstad', 's-Hertogenbosch', 'Haarlemmermeer', 'Zwolle', 'Zoetermeer',
            'Leiden', 'Maastricht', 'Ede', 'Dordrecht', 'Westland',
            'Alphen aan den Rijn', 'Alkmaar', 'Emmen', 'Delft', 'Venlo',
            'Deventer', 'Helmond', 'Oss', 'Amstelveen', 'Heerlen',
            'Nissewaard', 'Hengelo', 'Purmerend', 'Schiedam', 'Lelystad',
            'Leidschendam-Voorburg', 'Roermond', 'Vlaardingen', 'Gouda', 'Velsen',
            'Bergen op Zoom', 'Capelle aan den IJssel', 'Assen', 'Stichtse Vecht', 'Katwijk',
            'Veenendaal', 'Zeist', 'Nieuwegein', 'Hoogeveen', 'Hardenberg',
            'Lansingerland', 'Roosendaal', 'Kerkrade', 'Doetinchem', 'Den Helder',
            'Terneuzen', 'Gooi en Vechtstreek', 'Krimpenerwaard', 'Pijnacker-Nootdorp', 'Middelburg',
            'Uden', 'Veldhoven', 'Barneveld', 'Heerenveen', 'Zutphen',
            'Tiel', 'Wageningen', 'Geldrop-Mierlo', 'Rijswijk', 'Heemstede',
            'Barendrecht', 'Waalwijk', 'Harderwijk', 'Huizen', 'Beverwijk',
            'Heemskerk', 'Kampen', 'Soest', 'Woerden', 'Leusden',
            'Vught', 'Oosterhout', 'Drachten', 'Sneek', 'Emmeloord',
            'Delfzijl', 'Winschoten', 'Stadskanaal', 'Veendam', 'Appingedam',
            'Haren', 'Hoogezand-Sappemeer', 'Zuidhorn', 'Grootegast', 'Leek',
            'Dokkum', 'Franeker', 'Harlingen', 'Bolsward', 'IJlst',
            'Sloten', 'Stavoren', 'Hindeloopen', 'Workum', 'Joure',
            'Lemmer', 'Wolvega', 'Oosterwolde', 'Beilen', 'Westerbork',
            'Diever', 'Dwingeloo', 'Ruinen', 'Zuidwolde', 'Meppel',
            'Coevorden', 'Sleen', 'Zweeloo', 'Oosterhesselen', 'Dalen',
            'Klazienaveen', 'Nieuw-Amsterdam', 'Zwartsluis', 'Genemuiden', 'Hasselt',
            'Staphorst', 'Nieuwleusen', 'Dalfsen', 'Ommen', 'Raalte',
            'Heino', 'Lemelerveld', 'Wijhe', 'Olst', 'Bathmen',
            'Rijssen', 'Holten', 'Nijverdal', 'Hellendoorn', 'Wierden',
            'Enter', 'Almelo', 'Vriezenveen', 'Vroomshoop', 'Westerhaar',
            'Tubbergen', 'Geesteren', 'Albergen', 'Borne', 'Hengelo',
            'Oldenzaal', 'Losser', 'Overdinkel', 'Lonneker', 'Glane',
            'Haaksbergen', 'Boekelo', 'Diepenheim', 'Goor', 'Markelo',
            'Lochem', 'Laren', 'Barchem', 'Gorssel', 'Eefde',
            'Warnsveld', 'Vorden', 'Hengelo (Gld)', 'Zelhem', 'Humelo',
            'Keppel', 'Doesburg', 'Dieren', 'Velp', 'Rheden',
            'Duiven', 'Westervoort', 'Zevenaar', 'Didam', 'Lobith',
            'Pannerden', 'Gendt', 'Huissen', 'Bemmel', 'Elst',
            'Zetten', 'Andelst', 'Dodewaard', 'Opheusden', 'Kesteren',
            'Ochten', 'Lienden', 'Maurik', 'Eck en Wiel', 'Ingen',
            'Buren', 'Beusichem', 'Geldermalsen', 'Culemborg', 'Tiel',
            'Wamel', 'Dreumel', 'Alphen', 'Beneden-Leeuwen', 'Boven-Leeuwen',
            'Druten', 'Afferden', 'Puiflijk', 'Horssen', 'Beuningen',
            'Weurt', 'Ewijk', 'Winssen', 'Wijchen', 'Bergharen',
            'Nijmegen', 'Groesbeek', 'Beek', 'Ubbergen', 'Leuth',
            'Ooij', 'Millingen aan de Rijn', 'Malden', 'Heumen', 'Overasselt',
            'Nederasselt', 'Grave', 'Velp (NB)', 'Escharen', 'Gassel',
            'Cuijk', 'Vianen (NB)', 'Sint Agatha', 'Haps', 'Beers',
            'Mill', 'Sint Hubert', 'Wanroij', 'Langenboom', 'Wilbertoord',
            'Boxmeer', 'Beugen', 'Oeffelt', 'Rijkevoort', 'Vierlingsbeek',
            'Gennep', 'Ottersum', 'Milsbeek', 'Ven-Zelderheide', 'Heijen',
            'Afferden (L)', 'Siebengewald', 'Bergen (L)', 'Well', 'Wellerlooi',
            'Arcen', 'Velden', 'Lomm', 'Venlo', 'Blerick',
            'Tegelen', 'Steyl', 'Belfeld', 'Reuver', 'Beesel',
            'Swalmen', 'Boukoul', 'Roermond', 'Herten', 'Merum',
            'Ool', 'Asenray', 'Horn', 'Haelen', 'Nunhem',
            'Neer', 'Roggel', 'Heibloem', 'Meijel', 'Panningen',
            'Heldhelden', 'Beringe', 'Egchel', 'Koningslust', 'Grashoek',
            'Baarlo', 'Maasbree', 'Sevenum', 'Kronenberg', 'Evertsoord',
            'Horst', 'Melderslo', 'Meterik', 'Hegelsom', 'America',
            'Griendtsveen', 'Lottum', 'Grubbenvorst', 'Broekhuizen', 'Broekhuizenvorst',
            'Meerlo', 'Tienray', 'Swolgen', 'Wanssum', 'Blitterswijck'
        ],
        'PT' => [
            'Lisboa', 'Porto', 'Vila Nova de Gaia', 'Amadora', 'Braga',
            'Setúbal', 'Coimbra', 'Queluz', 'Funchal', 'Cacém',
            'Algueirão-Mem Martins', 'Loures', 'Rio de Mouro', 'Odivelas', 'Aveiro',
            'Barreiro', 'Amora', 'Corroios', 'Rio Tinto', 'São Domingos de Rana',
            'Leiria', 'Évora', 'Faro', 'Sesimbra', 'Guimarães',
            'Ermesinde', 'Portimão', 'Cascais', 'Maia', 'Viana do Castelo',
            'Beja', 'Vila Franca de Xira', 'Castelo Branco', 'Almada', 'Sintra',
            'Caldas da Rainha', 'Viseu', 'Torres Vedras', 'Santarém', 'Figueira da Foz',
            'Ponta Delgada', 'Angra do Heroísmo', 'Horta', 'Lajes do Pico', 'Madalena',
            'São Roque do Pico', 'Velas', 'Calheta (Açores)', 'Santa Cruz da Graciosa', 'Vila do Porto',
            'Povoação', 'Vila Franca do Campo', 'Lagoa', 'Ribeira Grande', 'Nordeste',
            'Santa Cruz das Flores', 'Lajes das Flores', 'Vila do Corvo', 'Câmara de Lobos', 'Machico',
            'Santa Cruz (Madeira)', 'Ribeira Brava', 'Pontas do Sol', 'Calheta (Madeira)', 'Porto Moniz',
            'São Vicente (Madeira)', 'Santana', 'Porto Santo', 'Chaves', 'Bragança',
            'Mirandela', 'Macedo de Cavaleiros', 'Vila Real', 'Peso da Régua', 'Lamego',
            'Tarouca', 'Armamar', 'Tabuaço', 'S. João da Pesqueira', 'Vila Nova de Foz Côa',
            'Miranda do Douro', 'Vimioso', 'Mogadouro', 'Freixo de Espada à Cinta', 'Torre de Moncorvo',
            'Vila Flor', 'Carrazeda de Ansiães', 'Alfândega da Fé', 'Vinhais', 'Montalegre',
            'Boticas', 'Valpaços', 'Murça', 'Alijó', 'Sabrosa',
            'Vila Pouca de Aguiar', 'Ribeira de Pena', 'Mondim de Basto', 'Arcos de Valdevez', 'Ponte da Barca',
            'Ponte de Lima', 'Paredes de Coura', 'Monção', 'Melgaço', 'Valença',
            'Vila Nova de Cerveira', 'Caminha', 'Esposende', 'Barcelos', 'Fafe',
            'Vieira do Minho', 'Póvoa de Lanhoso', 'Amares', 'Terras de Bouro', 'Vila Verde',
            'Vizela', 'Felgueiras', 'Lousada', 'Paços de Ferreira', 'Paredes',
            'Penafiel', 'Castelo de Paiva', 'Arouca', 'Vale de Cambra', 'Oliveira de Azeméis',
            'S. João da Madeira', 'Santa Maria da Feira', 'Espinho', 'Ovar', 'Murtosa',
            'Estarreja', 'Albergaria-a-Velha', 'Sever do Vouga', 'Águeda', 'Oliveira do Bairro',
            'Anadia', 'Mealhada', 'Vagos', 'Mira', 'Cantanhede',
            'Montemor-o-Velho', 'Soure', 'Condeixa-a-Nova', 'Miranda do Corvo', 'Lousã',
            'Góis', 'Vila Nova de Poiares', 'Penacova', 'Mortágua', 'Santa Comba Dão',
            'Tondela', 'Carregal do Sal', 'Nelas', 'Mangualde', 'Penalva do Castelo',
            'Sátão', 'Aguiar da Beira', 'Vila Nova de Paiva', 'Castro Daire', 'S. Pedro do Sul',
            'Vouzela', 'Oliveira de Frades', 'Resende', 'Cinfães', 'Moimenta da Beira',
            'Sernancelhe', 'Penedono', 'Mêda', 'Trancoso', 'Pinhel',
            'Almeida', 'Figueira de Castelo Rodrigo', 'Guarda', 'Celorico da Beira', 'Fornos de Algodres',
            'Gouveia', 'Seia', 'Manteigas', 'Covilhã', 'Belmonte',
            'Sabugal', 'Fundão', 'Penamacor', 'Idanha-a-Nova', 'Vila Velha de Ródão',
            'Proença-a-Nova', 'Oleiros', 'Sertã', 'Vila de Rei', 'Mação',
            'Abrantes', 'Sardoal', 'Constância', 'Vila Nova da Barquinha', 'Entroncamento',
            'Tomar', 'Ferreira do Zêzere', 'Alvaiázere', 'Ansião', 'Castanheira de Pêra',
            'Figueiró dos Vinhos', 'Pedrógão Grande', 'Pombal', 'Marinha Grande', 'Batalha',
            'Porto de Mós', 'Alcobaça', 'Nazaré', 'Óbidos', 'Peniche',
            'Bombarral', 'Lourinhã', 'Cadaval', 'Alenquer', 'Sobral de Monte Agraço',
            'Arruda dos Vinhos', 'Azambuja', 'Cartaxo', 'Rio Maior', 'Santarém',
            'Golegã', 'Chamusca', 'Alpiarça', 'Almeirim', 'Salvaterra de Magos',
            'Benavente', 'Coruche', 'Montijo', 'Alcochete', 'Palmela',
            'Moita', 'Montijo', 'Almada', 'Seixal', 'Sesimbra',
            'Setúbal', 'Vendas Novas', 'Montemor-o-Novo', 'Arraiolos', 'Mora',
            'Viana do Alentejo', 'Portel', 'Reguengos de Monsaraz', 'Alandroal', 'Vila Viçosa',
            'Borba', 'Estremoz', 'Sousel', 'Redondo', 'Mourão',
            'Barrancos', 'Moura', 'Serpa', 'Beja', 'Vidigueira',
            'Cuba', 'Ferreira do Alentejo', 'Aljustrel', 'Ourique', 'Castro Verde',
            'Almodôvar', 'Mértola', 'Odemira', 'Santiago do Cacém', 'Sines',
            'Grândola', 'Alcácer do Sal', 'Aljezur', 'Monchique', 'Vila do Bispo',
            'Lagos', 'Portimão', 'Lagoa', 'Silves', 'Albufeira',
            'Loulé', 'Faro', 'Olhão', 'São Brás de Alportel', 'Tavira',
            'Vila Real de Santo António', 'Castro Marim', 'Alcoutim', 'Ponte de Sor', 'Alter do Chão',
            'Crato', 'Gavião', 'Nisa', 'Castelo de Vide', 'Marvão',
            'Portalegre', 'Arronches', 'Monforte', 'Fronteira', 'Avis',
            'Campo Maior', 'Elvas', 'Ourém', 'Alcanena', 'Torres Novas'
        ],
    ];

    private const SUFFIXES = ['FC', 'CF', 'Athletic', 'United', 'City', 'Rovers', 'Town', 'SC', 'Deportivo', 'Wanderers'];

    private const SUFFIXES_BY_COUNTRY = [
        'ES' => [
            'CF', 'CD', 'UD', 'SD', 'Real', 'Atlético', 'Deportivo', 'Sporting', 'RCD', 'AD',
            'Racing', 'Recreativo', 'Gimnástic', 'Unión', 'Cultural', 'Rayo', 'Burgos', 'Poli', 'CDI', 'RCE',
            'Arenas', 'Europa', 'Condal', 'Levante', 'Hércules', 'Alcoyano', 'Mensajero', 'Izarra', 'Sestao', 'Castilla'
        ],
        'EN' => [
            'FC', 'United', 'City', 'Town', 'Rovers', 'Wanderers', 'Athletic', 'Albion', 'County', 'Villa',
            'North End', 'Alexandra', 'Harriers', 'Orient', 'Argyle', 'Wednesday', 'Forest', 'Rangers', 'Stanley', 'Dons',
            'Diamonds', 'Miners', 'Warriors', 'Knights', 'Spurs', 'Heaths', 'Bridge', 'Vale', 'Park', 'Port'
        ],
        'DE' => [
            'SV', '1. FC', 'SpVgg', 'TSV', 'VfB', 'VfL', 'SC', 'Eintracht', 'Fortuna', 'Borussia',
            'Arminia', 'Germania', 'Preußen', 'Hansa', 'Viktoria', 'Wacker', 'Kickers', 'Sportfreunde', 'TuS', 'FSV',
            'Union', 'Dynamo', 'Hertha', 'Rot-Weiß', 'Schwarz-Weiß', 'Blau-Weiß', 'Phönix', 'Stahl', 'Chemie', 'Lokomotive'
        ],
        'IT' => [
            'AC', 'AS', 'FC', 'US', 'Polisportiva', 'Virtus', 'Calcio', 'Hellas', 'Unione Sportiva', 'SS',
            'Genoa', 'Pro', 'Samp', 'Atalanta', 'Internazionale', 'Chievo', 'Bari', 'Palermo', 'Spal', 'Piacenza',
            'Vigor', 'Libertas', 'Città di', 'Audace', 'Olimpia', 'Sangiovannese', 'Real', 'Borgo', 'Aquila', 'Grifone'
        ],
        'FR' => [
            'AS', 'FC', 'Olympique', 'RC', 'Stade', 'US', 'ES', 'Girondins', 'SC', 'OGC',
            'Amiens', 'Étoile', 'Racing', 'Red Star', 'Sporting', 'Toulousain', 'Mousquetaires', 'Lorientais', 'Nimois', 'Brestois',
            'Aigles', 'Azur', 'Lumière', 'Nord', 'Sud', 'Montagnards', 'Vignerons', 'Corsica', 'Rhodaniens', 'Alpins'
        ],
        'BR' => [
            'EC', 'AC', 'SC', 'FC', 'CR', 'Esporte Clube', 'Grêmio', 'Atlético', 'Sociedade Esportiva', 'Botafogo',
            'Nacional', 'Comercial', 'Ferroviária', 'XV de', 'Independente', 'Operário', 'Sampaio', 'Remo', 'Paysandu', 'América',
            'Paulista', 'Carioca', 'Mineiro', 'Gaúcho', 'Nordeste', 'Luso', 'Real', 'União', 'Juventude', 'Vila Nova'
        ],
        'AR' => [
            'CA', 'Club Atlético', 'Social y Deportivo', 'AC', 'CSD', 'Gimnasia', 'Unión', 'Sportivo', 'Racing', 'Estudiantes',
            'Defensores de', 'Ferro Carril', 'Talleres', 'Central', 'Huracán', 'Patria', 'Chacarita', 'Almagro', 'Arsenal', 'Independiente',
            'Belgrano', 'Sarmiento', 'Mitre', 'Douglas Haig', 'Guaraní', 'Crucero', 'Aldosivi', 'Patronato', 'Banfield', 'Lanús'
        ],
        'NL' => [
            'FC', 'SV', 'SC', 'AZ', 'VV', 'Jong', 'Sparta', 'Excelsior', 'Fortuna', 'Go Ahead',
            'Willem', 'Heracles', 'Vitesse', 'Heerenveen', 'Twente', 'Graafschap', 'Cambuur', 'Roda', 'Telstar', 'Volendam',
            'Unitas', 'Quick', 'Harkemase', 'IJsselmeervogels', 'Spakenburg', 'Katwijk', 'Noordwijk', 'Koninklijke', 'Blauw Wit', 'Zeeburgia'
        ],
        'PT' => [
            'FC', 'SC', 'CD', 'GD', 'Clube', 'União', 'Académica', 'Vitória', 'Boavista', 'Os Belenenses',
            'Sporting Clube', 'Marítimo', 'Nacional', 'Gil Vicente', 'Paços de Ferreira', 'Moreirense', 'Arouca', 'Tondela', 'Farense', 'Olhanense',
            'Leixões', 'Varzim', 'Mafra', 'Covilhã', 'Feirense', 'Penafiel', 'Desportivo', 'Lusitano', 'Campomaiorense', 'Beira-Mar'
        ],
    ];

    private const STADIUM_FORMATS_BY_COUNTRY = [
        'ES' => ['Estadio %s', 'Estadio de %s', 'Campo de %s'],
        'EN' => ['%s Park', '%s Ground', 'The %s Stadium', '%s Arena'],
        'DE' => ['%s Arena', '%s Stadion', 'Arena %s'],
        'IT' => ['Stadio %s', 'Stadio Comunale %s', 'Arena %s'],
        'FR' => ['Stade %s', 'Stade Municipal de %s', 'Stade de %s'],
        'BR' => ['Estádio %s', 'Arena %s', 'Estádio Municipal de %s'],
        'AR' => ['Estadio %s', 'Estadio Municipal %s', 'Cancha %s'],
        'NL' => ['%s Stadion', 'Stadion %s', '%s Arena'],
        'PT' => ['Estádio %s', 'Estádio Municipal de %s', 'Estádio do %s'],
    ];

    private const COLORS = [
        '#c0392b', '#2980b9', '#27ae60', '#8e44ad', '#f39c12',
        '#16a085', '#d35400', '#2c3e50', '#e74c3c', '#1abc9c',
        '#3498db', '#9b59b6', '#e67e22', '#1a252f', '#ffffff',
        '#2ecc71', '#e8d44d', '#34495e', '#922b21', '#1f618d',
    ];

    /**
     * Facility level ranges by tier band.
     * Each entry: [min, max, training range, stands range, other range]
     */
    private const FACILITY_LEVELS = [
        ['min' => 1, 'max' => 2, 'training' => [7, 9], 'stands' => [4, 5], 'other' => [3, 5]],
        ['min' => 3, 'max' => 4, 'training' => [5, 6], 'stands' => [3, 4], 'other' => [2, 3]],
        ['min' => 5, 'max' => 6, 'training' => [3, 4], 'stands' => [2, 3], 'other' => [1, 2]],
        ['min' => 7, 'max' => 8, 'training' => [1, 2], 'stands' => [0, 1], 'other' => [0, 1]],
    ];

    // Slugs classified by facility type for level band selection
    private const TRAINING_SLUGS = ['training_pitch', 'strength_suite'];
    private const STANDS_SLUGS   = ['north_stand', 'south_stand', 'east_stand', 'west_stand'];

    public function __construct(
        private readonly EntityManagerInterface      $em,
        private readonly FacilityTemplateRepository  $facilityTemplateRepo,
        private readonly NpcClubRepository           $npcClubRepo,
        private readonly LeagueService               $leagueService,
        private readonly GameConfigRepository        $gameConfigRepository,
    ) {}

    /** @return string[] */
    public function getPlaceNames(string $countryCode): array
    {
        return self::PLACE_NAMES_BY_COUNTRY[$countryCode] ?? [];
    }

    /** @return string[] */
    public function getSuffixes(string $countryCode): array
    {
        return self::SUFFIXES_BY_COUNTRY[$countryCode] ?? [];
    }

    /** @return NpcClub[] */
    public function generateClubs(int $count, int $tier, string $country, bool $deleteExisting = false): array
    {
        $tier       = max(1, min(8, $tier));
        $slugs      = $this->getActiveFacilitySlugs();
        $bandIndex  = $this->getBandIndexForTier($tier);
        $levelBand  = self::FACILITY_LEVELS[$bandIndex];
        $placeNames = self::PLACE_NAMES_BY_COUNTRY[$country] ?? ['Capital', 'Northern', 'Southern', 'Eastern', 'Western', 'Central'];
        $suffixes   = self::SUFFIXES_BY_COUNTRY[$country];
        $usedNames  = [];
        $clubs      = [];

        if ($deleteExisting) {
            $this->npcClubRepo->deleteByCountryAndTier($country, $tier);
        }

        for ($i = 0; $i < $count; $i++) {
            [$name, $place] = $this->generateName($placeNames, $usedNames, $suffixes);
            $usedNames[]    = $name;
            $reputation     = $this->reputationForTier($tier);
            $balance        = $this->balanceForTier($tier);
            $facilities     = $this->buildFacilities($slugs, $levelBand, $bandIndex);
            $colors         = $this->pickColorPair();
            $stadiumName    = $this->generateStadiumName($place, $country);

            $club = new NpcClub(
                name:           $name,
                country:        $country,
                tier:           $tier,
                reputation:     $reputation,
                primaryColor:   $colors[0],
                secondaryColor: $colors[1],
                balance:        $balance,
                facilities:     $facilities,
            );
            $club->setStadiumName($stadiumName);
            $club->setPlayingStyle($this->playingStyleForTier($tier));
            $club->setFinancialApproach($this->financialApproachForTier($tier));
            $club->setManagerTemperament(random_int(30, 80));

            $this->em->persist($club);
            $this->leagueService->assignClubToLeague($club);
            $clubs[] = $club;
        }

        $this->em->flush();
        return $clubs;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /** @return string[] active facility slugs from DB */
    private function getActiveFacilitySlugs(): array
    {
        $templates = $this->facilityTemplateRepo->findBy(['isActive' => true]);
        return array_map(fn(FacilityTemplate $t) => $t->getSlug(), $templates);
    }

    private function getBandIndexForTier(int $tier): int
    {
        foreach (self::FACILITY_LEVELS as $i => $band) {
            if ($tier >= $band['min'] && $tier <= $band['max']) {
                return $i;
            }
        }
        return 3;
    }

    private function buildFacilities(array $slugs, array $band, int $bandIndex): array
    {
        $config     = $this->gameConfigRepository->getConfig();
        $facilities = [];
        foreach ($slugs as $slug) {
            $override = $config->getNpcFacilityLevelRangeForSlugAndBand($slug, $bandIndex);
            if ($override !== null) {
                $min = (int) $override['min'];
                $max = (int) $override['max'];
                if ($min === 0 && $max === 0) {
                    continue; // facility excluded for this tier band
                }
                $max = max($min, $max);
            } elseif (in_array($slug, self::TRAINING_SLUGS, true)) {
                [$min, $max] = $band['training'];
            } elseif (in_array($slug, self::STANDS_SLUGS, true)) {
                [$min, $max] = $band['stands'];
            } else {
                [$min, $max] = $band['other'];
            }
            $facilities[$slug] = random_int($min, $max);
        }
        return $facilities;
    }

    /** @return array{string, string} [name, place] */
    private function generateName(array $placeNames, array $usedNames, array $suffixes): array
    {
        $attempts = 0;
        do {
            $place  = $placeNames[array_rand($placeNames)];
            $suffix = $suffixes[array_rand($suffixes)];
            $name   = "{$place} {$suffix}";
            $attempts++;
        } while (in_array($name, $usedNames, true) && $attempts < 50);

        return [$name, $place];
    }

    private function generateStadiumName(string $place, string $country): string
    {
        $formats = self::STADIUM_FORMATS_BY_COUNTRY[$country] ?? ['%s Stadium', '%s Ground', 'The %s Arena'];
        $format  = $formats[array_rand($formats)];
        return sprintf($format, $place);
    }

    private function reputationForTier(int $tier): int
    {
        // tier 1 → 70–90, tier 8 → 5–20 (linear interpolation)
        $minRep = (int) round(70 - ($tier - 1) * (65 / 7));
        $maxRep = (int) round(90 - ($tier - 1) * (70 / 7));
        return random_int(max(1, $minRep), max(1, $maxRep));
    }

    private function balanceForTier(int $tier): int
    {
        $range = $this->gameConfigRepository->getConfig()->getNpcClubBalanceRangeForTier($tier);
        $min   = max(0, (int) $range['min']);
        $max   = max($min, (int) $range['max']);
        return random_int($min, $max);
    }

    /** @return string[] [primaryColor, secondaryColor] */
    private function pickColorPair(): array
    {
        $colors  = self::COLORS;
        $primary = $colors[array_rand($colors)];

        // Try up to 20 random picks for a contrasting secondary
        for ($i = 0; $i < 20; $i++) {
            $secondary = $colors[array_rand($colors)];
            if ($secondary !== $primary && $this->contrastRatio($primary, $secondary) >= 3.0) {
                return [$primary, $secondary];
            }
        }

        // Fallback: pick whichever available color yields the highest contrast
        $best      = null;
        $bestRatio = 0.0;
        foreach ($colors as $candidate) {
            if ($candidate === $primary) {
                continue;
            }
            $ratio = $this->contrastRatio($primary, $candidate);
            if ($ratio > $bestRatio) {
                $bestRatio = $ratio;
                $best      = $candidate;
            }
        }

        return [$primary, $best ?? $colors[0]];
    }

    private function contrastRatio(string $hexA, string $hexB): float
    {
        $la = $this->relativeLuminance($hexA);
        $lb = $this->relativeLuminance($hexB);
        [$lighter, $darker] = $la > $lb ? [$la, $lb] : [$lb, $la];
        return ($lighter + 0.05) / ($darker + 0.05);
    }

    private function relativeLuminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        $r   = hexdec(substr($hex, 0, 2)) / 255;
        $g   = hexdec(substr($hex, 2, 2)) / 255;
        $b   = hexdec(substr($hex, 4, 2)) / 255;

        $linearise = static fn(float $c): float =>
            $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;

        return 0.2126 * $linearise($r) + 0.7152 * $linearise($g) + 0.0722 * $linearise($b);
    }

    private function playingStyleForTier(int $tier): string
    {
        $styles = ['POSSESSION', 'DIRECT', 'COUNTER', 'HIGH_PRESS'];
        return $styles[array_rand($styles)];
    }

    private function financialApproachForTier(int $tier): string
    {
        // Lower tier numbers (elite) lean SPECULATIVE; higher tier numbers (lower league) lean CONSERVATIVE
        if ($tier <= 2) {
            $options = ['SPECULATIVE', 'SPECULATIVE', 'SPECULATIVE', 'BALANCED', 'BALANCED', 'CONSERVATIVE'];
        } elseif ($tier <= 5) {
            $options = ['SPECULATIVE', 'BALANCED', 'BALANCED', 'BALANCED', 'CONSERVATIVE', 'CONSERVATIVE'];
        } else {
            $options = ['SPECULATIVE', 'BALANCED', 'CONSERVATIVE', 'CONSERVATIVE', 'CONSERVATIVE', 'CONSERVATIVE'];
        }
        return $options[array_rand($options)];
    }
}
