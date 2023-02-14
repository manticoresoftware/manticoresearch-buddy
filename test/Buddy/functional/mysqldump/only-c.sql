/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `c`
--

DROP TABLE IF EXISTS `c`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE c (
id bigint,
v2 integer engine='columnar',
v3 json,
v1 text
);
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `c`
--
-- WHERE:  id > 0 ORDER BY id ASC

LOCK TABLES `c` WRITE;
/*!40000 ALTER TABLE `c` DISABLE KEYS */;
INSERT INTO `c` VALUES (1,'63d767ed1c615',0,'{\"key\":892,\"value\":\"63d767ed1c616\"}'),(2,'63d767ed1c61b',510,'{\"key\":630,\"value\":\"63d767ed1c61c\"}'),(3,'63d767ed1c620',1162,'{\"key\":171,\"value\":\"63d767ed1c621\"}'),(4,'63d767ed1c626',2724,'{\"key\":813,\"value\":\"63d767ed1c627\"}'),(5,'63d767ed1c62b',2908,'{\"key\":979,\"value\":\"63d767ed1c62c\"}'),(6,'63d767ed1c630',400,'{\"key\":279,\"value\":\"63d767ed1c631\"}'),(7,'63d767ed1c635',3678,'{\"key\":247,\"value\":\"63d767ed1c636\"}'),(8,'63d767ed1c63a',4865,'{\"key\":633,\"value\":\"63d767ed1c63b\"}'),(9,'63d767ed1c641',1024,'{\"key\":408,\"value\":\"63d767ed1c642\"}'),(10,'63d767ed1c646',2052,'{\"key\":68,\"value\":\"63d767ed1c647\"}'),(11,'63d767ed1c64b',6470,'{\"key\":601,\"value\":\"63d767ed1c64c\"}'),(12,'63d767ed1c650',1287,'{\"key\":821,\"value\":\"63d767ed1c651\"}'),(13,'63d767ed1c655',5256,'{\"key\":40,\"value\":\"63d767ed1c656\"}'),(14,'63d767ed1c65a',4667,'{\"key\":40,\"value\":\"63d767ed1c65b\"}'),(15,'63d767ed1c65f',1176,'{\"key\":456,\"value\":\"63d767ed1c660\"}'),(16,'63d767ed1c664',11595,'{\"key\":560,\"value\":\"63d767ed1c665\"}'),(17,'63d767ed1c66a',3424,'{\"key\":480,\"value\":\"63d767ed1c66b\"}'),(18,'63d767ed1c66e',5423,'{\"key\":386,\"value\":\"63d767ed1c66f\"}'),(19,'63d767ed1c673',2034,'{\"key\":75,\"value\":\"63d767ed1c674\"}'),(20,'63d767ed1c678',15979,'{\"key\":555,\"value\":\"63d767ed1c679\"}');
/*!40000 ALTER TABLE `c` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;
